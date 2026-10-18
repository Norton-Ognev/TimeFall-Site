<?php
require_once 'config.php';
header('Content-Type: application/json');

// --- проверка авторизации ---
$isAuth = isset($_SESSION['user_id']);
$user   = null;
if ($isAuth) {
    $stmt = $pdo->prepare("SELECT id, minecraft_nick, has_pass FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- читаем входные данные ---
$method    = $_SERVER['REQUEST_METHOD'];
$inputJSON = file_get_contents('php://input');
$postData  = json_decode($inputJSON, true) ?? [];

// action может быть в GET-параметре, в теле JSON или в обычном POST (form-data)
$action = $_GET['action'] ?? $_POST['action'] ?? $postData['action'] ?? '';

switch ($action) {

    // ========== СПИСОК ОРГАНИЗАЦИЙ ==========
    case 'list':
        $page    = max(1, intval($_GET['page'] ?? 1));
        $perPage = min(50, max(1, intval($_GET['per_page'] ?? 12)));
        $search  = trim($_GET['search'] ?? '');
        $joinType = $_GET['join_type'] ?? '';
    
        $where  = [];
        $params = [];
        if ($search !== '') {
            $where[]  = "o.name LIKE ?";
            $params[] = "%$search%";
        }
        if ($joinType === 'open' || $joinType === 'request') {
            $where[]  = "o.join_type = ?";
            $params[] = $joinType;
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
        // Общее количество организаций (с учётом фильтров)
        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM organisations o LEFT JOIN memberships m ON o.id = m.organisation_id $whereSQL");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
    
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("
            SELECT o.*, u.minecraft_nick as owner_nick, COUNT(m.id) as member_count
            FROM organisations o
            JOIN users u ON o.owner_id = u.id
            LEFT JOIN memberships m ON o.id = m.organisation_id
            $whereSQL
            GROUP BY o.id
            ORDER BY member_count DESC, o.id ASC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        echo json_encode([
            'success'       => true,
            'organizations' => $orgs,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $perPage
        ]);
        break;

    // ========== МОЯ ОРГАНИЗАЦИЯ ==========
    case 'my_org':
        if (!$isAuth) {
            echo json_encode(['success' => false, 'error' => 'Not authorized']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT o.* FROM organisations o WHERE o.owner_id = ?");
        $stmt->execute([$user['id']]);
        $myOrg = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success'      => true,
            'organization' => $myOrg ?: null
        ]);
        break;

    // ========== ПОЛУЧИТЬ ОДНУ ОРГАНИЗАЦИЮ ==========
    case 'get':
        $id = intval($_GET['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org) {
            echo json_encode(['success' => false, 'error' => 'Организация не найдена']);
            break;
        }

        // участники
        $stmt = $pdo->prepare("
            SELECT m.role, u.minecraft_nick, u.id as user_id
            FROM memberships m
            JOIN users u ON m.player_id = u.id
            WHERE m.organisation_id = ?
            ORDER BY (m.role = 'owner') DESC, u.id ASC
        ");
        $stmt->execute([$id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // статус текущего пользователя
        $userStatus = [
            'is_member'          => false,
            'is_owner'           => false,
            'has_pending_request' => false,
            'can_join'           => false,
            'member_count'       => 0,
            'role'               => null
        ];
        if ($isAuth) {
            $stmt = $pdo->prepare("SELECT role FROM memberships WHERE player_id = ? AND organisation_id = ?");
            $stmt->execute([$user['id'], $id]);
            $mem = $stmt->fetch();
            if ($mem) {
                $userStatus['is_member'] = true;
                $userStatus['is_owner']  = ($mem['role'] === 'owner');
                $userStatus['role']      = $mem['role'];  // ← добавить эту строку
            }

            $stmt = $pdo->prepare("SELECT id FROM join_requests WHERE player_id = ? AND organisation_id = ? AND status = 'pending'");
            $stmt->execute([$user['id'], $id]);
            $userStatus['has_pending_request'] = (bool)$stmt->fetch();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE player_id = ?");
            $stmt->execute([$user['id']]);
            $userStatus['member_count'] = $stmt->fetchColumn();

            $userStatus['can_join'] = (
                $user['has_pass'] == 1 &&
                $userStatus['member_count'] < 5 &&
                !$userStatus['is_member']
            );
        }

        echo json_encode([
            'success'      => true,
            'organization' => $org,
            'members'      => $members,
            'userStatus'   => $userStatus
        ]);
        break;

    // ========== СОЗДАТЬ ОРГАНИЗАЦИЮ ==========
    case 'create':
        if (!$isAuth || $user['has_pass'] != 1) {
            echo json_encode(['success' => false, 'error' => 'Нужна проходка']);
            exit;
        }
        if ($method !== 'POST') exit;

        // Проверка на существующую организацию
        $stmt = $pdo->prepare("SELECT id FROM organisations WHERE owner_id = ?");
        $stmt->execute([$user['id']]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'У вас уже есть организация']);
            exit;
        }

        $name = trim($postData['name'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 25) {
            echo json_encode(['success' => false, 'error' => 'Название от 2 до 25 символов']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM organisations WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Такая организация уже существует']);
            exit;
        }

        $desc        = trim($postData['description'] ?? '');
        $shortDesc   = mb_substr(trim($postData['short_description'] ?? ''), 0, 120);  // ← ВОТ ЭТО ДОБАВЬ
        $baseColor   = $postData['banner_base_color'] ?? 'white';
        $patterns    = $postData['banner_patterns'] ?? [];
        $joinType    = in_array($postData['join_type'] ?? 'open', ['open', 'request'])
                            ? $postData['join_type'] : 'open';
        $bannerImage = $postData['banner_image'] ?? '';

        // сохраняем картинку
        $imagePath = null;
        if ($bannerImage && strpos($bannerImage, 'data:image/png;base64,') === 0) {
            $bannerImage = str_replace('data:image/png;base64,', '', $bannerImage);
            $decoded = base64_decode($bannerImage);
            $filename = 'banner_' . uniqid() . '.png';
            $saveDir = __DIR__ . '/../img/org_banners/';
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }
            file_put_contents($saveDir . $filename, $decoded);
            $imagePath = 'img/org_banners/' . $filename;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO organisations
                    (name, description, short_description, banner_base_color, banner_patterns, banner_image, owner_id, join_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name,
                $desc,
                $shortDesc,
                $baseColor,
                json_encode($patterns),
                $imagePath,
                $user['id'],
                $joinType
            ]);
            $orgId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO memberships (player_id, organisation_id, role) VALUES (?, ?, 'owner')");
            $stmt->execute([$user['id'], $orgId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $orgId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
        }
        break;

    // ========== ОБНОВИТЬ ОРГАНИЗАЦИЮ ==========
    case 'update':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;

        $id  = intval($postData['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org || $org['owner_id'] != $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $name = trim($postData['name'] ?? $org['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 25) {
            echo json_encode(['success' => false, 'error' => 'Название от 2 до 25 символов']);
            exit;
        }

        $desc        = trim($postData['description'] ?? $org['description']);
        $shortDesc   = mb_substr(trim($postData['short_description'] ?? $org['short_description'] ?? ''), 0, 120);
        $baseColor   = $postData['banner_base_color'] ?? $org['banner_base_color'];
        $patterns    = $postData['banner_patterns'] ?? json_decode($org['banner_patterns'], true);
        $joinType    = in_array($postData['join_type'] ?? $org['join_type'], ['open', 'request'])
                            ? $postData['join_type'] : $org['join_type'];
        $bannerImage = $postData['banner_image'] ?? null;

        $imagePath = $org['banner_image'];
        if ($bannerImage && strpos($bannerImage, 'data:image/png;base64,') === 0) {
            if ($imagePath && file_exists(__DIR__ . '/../' . $imagePath)) {
                @unlink(__DIR__ . '/../' . $imagePath);
            }
            $bannerImage = str_replace('data:image/png;base64,', '', $bannerImage);
            $decoded = base64_decode($bannerImage);
            $filename = 'banner_' . uniqid() . '.png';
            $saveDir = __DIR__ . '/../img/org_banners/';
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }
            file_put_contents($saveDir . $filename, $decoded);
            $imagePath = 'img/org_banners/' . $filename;
        }

        $stmt = $pdo->prepare("
            UPDATE organisations
            SET name = ?, description = ?, short_description = ?, banner_base_color = ?, banner_patterns = ?, banner_image = ?, join_type = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $desc, $shortDesc, $baseColor, json_encode($patterns), $imagePath, $joinType, $id]);
        echo json_encode(['success' => true]);
        break;

    // ========== УДАЛИТЬ ОРГАНИЗАЦИЮ ==========
    case 'delete':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;

        $id  = intval($postData['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org || $org['owner_id'] != $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        if ($org['banner_image'] && file_exists(__DIR__ . '/../' . $org['banner_image'])) {
            @unlink(__DIR__ . '/../' . $org['banner_image']);
        }
        $stmt = $pdo->prepare("DELETE FROM organisations WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ========== ВСТУПИТЬ (открытая) ==========
    case 'join':
        if (!$isAuth || $user['has_pass'] != 1) exit;
        if ($method !== 'POST') exit;

        $id  = intval($postData['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org || $org['join_type'] !== 'open') {
            echo json_encode(['success' => false, 'error' => 'Вступление невозможно']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE player_id = ?");
        $stmt->execute([$user['id']]);
        if ($stmt->fetchColumn() >= 5) {
            echo json_encode(['success' => false, 'error' => 'Достигнут лимит организаций (5)']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM memberships WHERE player_id = ? AND organisation_id = ?");
        $stmt->execute([$user['id'], $id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Вы уже участник']);
            exit;
        }
        
        // Проверка чёрного списка
        $stmt = $pdo->prepare("SELECT id FROM org_blacklist WHERE organisation_id = ? AND player_id = ?");
        $stmt->execute([$id, $user['id']]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Вы в чёрном списке этой организации']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO memberships (player_id, organisation_id) VALUES (?, ?)");
        $stmt->execute([$user['id'], $id]);
        echo json_encode(['success' => true]);
        break;

    // ========== ПОДАТЬ ЗАЯВКУ ==========
    case 'request':
        if (!$isAuth || $user['has_pass'] != 1) exit;
        if ($method !== 'POST') exit;

        $id  = intval($postData['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org || $org['join_type'] !== 'request') {
            echo json_encode(['success' => false, 'error' => 'Организация не принимает заявки']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE player_id = ?");
        $stmt->execute([$user['id']]);
        if ($stmt->fetchColumn() >= 5) {
            echo json_encode(['success' => false, 'error' => 'Достигнут лимит организаций']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM memberships WHERE player_id = ? AND organisation_id = ?");
        $stmt->execute([$user['id'], $id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Вы уже участник']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM join_requests WHERE player_id = ? AND organisation_id = ? AND status = 'pending'");
        $stmt->execute([$user['id'], $id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Заявка уже отправлена']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM org_blacklist WHERE organisation_id = ? AND player_id = ?");
        $stmt->execute([$id, $user['id']]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Вы в чёрном списке этой организации']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO join_requests (player_id, organisation_id) VALUES (?, ?)");
        $stmt->execute([$user['id'], $id]);
        addNotification($pdo, $org['owner_id'], 'join_request', json_encode([
            'org_id'   => $id,
            'org_name' => $org['name'],
            'nick'     => $user['minecraft_nick']
        ]));
        echo json_encode(['success' => true]);
        break;

    // ========== ПРИНЯТЬ ЗАЯВКУ ==========
    case 'accept_request':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;

        $reqId = intval($postData['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT r.*, o.owner_id FROM join_requests r JOIN organisations o ON r.organisation_id = o.id WHERE r.id = ?");
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        if (!$req || $req['status'] !== 'pending' || !canManage($pdo, $req['organisation_id'], $user['id'])) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE player_id = ?");
        $stmt->execute([$req['player_id']]);
        if ($stmt->fetchColumn() >= 5) {
            $stmt = $pdo->prepare("UPDATE join_requests SET status = 'declined' WHERE id = ?");
            $stmt->execute([$reqId]);
            echo json_encode(['success' => false, 'error' => 'Игрок уже состоит в 5 организациях']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE join_requests SET status = 'accepted' WHERE id = ?");
            $stmt->execute([$reqId]);

            $stmt = $pdo->prepare("INSERT INTO memberships (player_id, organisation_id) VALUES (?, ?)");
            $stmt->execute([$req['player_id'], $req['organisation_id']]);

            $org = getOrg($pdo, $req['organisation_id']);
            addNotification($pdo, $req['player_id'], 'request_accepted', json_encode([
                'org_id'   => $org['id'],
                'org_name' => $org['name']
            ]));

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ошибка']);
        }
        break;

    // ========== ОТКЛОНИТЬ ЗАЯВКУ ==========
    case 'decline_request':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;

        $reqId = intval($postData['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT r.*, o.owner_id FROM join_requests r JOIN organisations o ON r.organisation_id = o.id WHERE r.id = ?");
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        if (!$req || !canManage($pdo, $req['organisation_id'], $user['id'])) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE join_requests SET status = 'declined' WHERE id = ?");
        $stmt->execute([$reqId]);

        $org = getOrg($pdo, $req['organisation_id']);
        addNotification($pdo, $req['player_id'], 'request_declined', json_encode([
            'org_id'   => $org['id'],
            'org_name' => $org['name']
        ]));
        echo json_encode(['success' => true]);
        break;

    // ========== ПОКИНУТЬ ОРГАНИЗАЦИЮ ==========
    case 'leave':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;

        $id  = intval($postData['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org) exit;

        if ($org['owner_id'] == $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Сначала передайте владение']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM memberships WHERE player_id = ? AND organisation_id = ?");
        $stmt->execute([$user['id'], $id]);
        echo json_encode(['success' => true]);
        break;

    // ========== ПЕРЕДАТЬ ВЛАДЕНИЕ ==========
    case 'transfer':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;

        $id         = intval($postData['id'] ?? 0);
        $newOwnerId = intval($postData['new_owner_id'] ?? 0);
        $org        = getOrg($pdo, $id);

        if (!$org || $org['owner_id'] != $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        // Проверяем, что новый владелец не владеет другой организацией
        $stmt = $pdo->prepare("SELECT id FROM organisations WHERE owner_id = ? AND id != ? LIMIT 1");
        $stmt->execute([$newOwnerId, $id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Игрок уже владеет другой организацией']);
            exit;
        }

        // Проверяем, что новый владелец является участником этой организации
        $stmt = $pdo->prepare("SELECT id FROM memberships WHERE player_id = ? AND organisation_id = ?");
        $stmt->execute([$newOwnerId, $id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Игрок не является участником']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Старый владелец -> обычный участник
            $stmt = $pdo->prepare("UPDATE memberships SET role = 'member' WHERE player_id = ? AND organisation_id = ?");
            $stmt->execute([$user['id'], $id]);

            // Новый владелец
            $stmt = $pdo->prepare("UPDATE memberships SET role = 'owner' WHERE player_id = ? AND organisation_id = ?");
            $stmt->execute([$newOwnerId, $id]);

            $stmt = $pdo->prepare("UPDATE organisations SET owner_id = ? WHERE id = ?");
            $stmt->execute([$newOwnerId, $id]);

            addNotification($pdo, $newOwnerId, 'ownership_transferred', json_encode([
                'org_id'   => $id,
                'org_name' => $org['name']
            ]));

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ошибка']);
        }
        break;
    // ========== ЗАЯВКИ (для владельца) ==========
    case 'requests':
        if (!$isAuth) exit;

        $id  = intval($_GET['id'] ?? 0);
        $org = getOrg($pdo, $id);
        if (!$org || !canManage($pdo, $id, $user['id'])) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT r.id, r.status, r.created_at, u.minecraft_nick
            FROM join_requests r
            JOIN users u ON r.player_id = u.id
            WHERE r.organisation_id = ? AND r.status = 'pending'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$id]);
        $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'requests' => $reqs]);
        break;
        
    case 'kick':
        if (!$isAuth) exit;
        if ($method !== 'POST') exit;
        $orgId = intval($postData['organisation_id'] ?? 0);
        $targetPlayerId = intval($postData['player_id'] ?? 0);
        $org = getOrg($pdo, $orgId);
        if (!$org || $org['owner_id'] != $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        if ($targetPlayerId == $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Нельзя исключить самого себя']);
            exit;
        }
        // Проверяем, что игрок состоит в организации
        $stmt = $pdo->prepare("SELECT id FROM memberships WHERE player_id = ? AND organisation_id = ?");
        $stmt->execute([$targetPlayerId, $orgId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Игрок не найден']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM memberships WHERE player_id = ? AND organisation_id = ?");
        $stmt->execute([$targetPlayerId, $orgId]);
        echo json_encode(['success' => true]);
        break;
        
        case 'blacklist_list':
            if (!$isAuth) exit;
            $orgId = intval($_GET['id'] ?? 0);
            $org = getOrg($pdo, $orgId);
            if (!$org || !canManage($pdo, $orgId, $user['id'])) {
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT b.id, b.player_id, u.minecraft_nick, b.created_at
                FROM org_blacklist b
                JOIN users u ON b.player_id = u.id
                WHERE b.organisation_id = ?
                ORDER BY b.created_at DESC
            ");
            $stmt->execute([$orgId]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'blacklist' => $list]);
            break;
            
        case 'blacklist_add':
            if (!$isAuth) exit;
            if ($method !== 'POST') exit;
            $orgId = intval($postData['organisation_id'] ?? 0);
            $org = getOrg($pdo, $orgId);
            if (!$org || !canManage($pdo, $orgId, $user['id'])) {
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $targetNick = trim($postData['nick'] ?? '');
            if ($targetNick === '') {
                echo json_encode(['success' => false, 'error' => 'Укажите ник игрока']);
                exit;
            }
            // Находим игрока по нику
            $stmt = $pdo->prepare("SELECT id FROM users WHERE minecraft_nick = ?");
            $stmt->execute([$targetNick]);
            $target = $stmt->fetch();
            if (!$target) {
                echo json_encode(['success' => false, 'error' => 'Игрок не найден']);
                exit;
            }
            $targetId = $target['id'];
            // Нельзя добавить владельца
            if ($targetId == $user['id']) {
                echo json_encode(['success' => false, 'error' => 'Нельзя добавить себя']);
                exit;
            }
            // Проверка на дубликат
            $stmt = $pdo->prepare("SELECT id FROM org_blacklist WHERE organisation_id = ? AND player_id = ?");
            $stmt->execute([$orgId, $targetId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Игрок уже в чёрном списке']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO org_blacklist (organisation_id, player_id, added_by) VALUES (?, ?, ?)");
            $stmt->execute([$orgId, $targetId, $user['id']]);
            echo json_encode(['success' => true]);
            break;
            
        case 'blacklist_remove':
            if (!$isAuth) exit;
            if ($method !== 'POST') exit;
            $orgId = intval($postData['organisation_id'] ?? 0);
            $org = getOrg($pdo, $orgId);
            if (!$org || !canManage($pdo, $orgId, $user['id'])) {
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $entryId = intval($postData['entry_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM org_blacklist WHERE id = ? AND organisation_id = ?");
            $stmt->execute([$entryId, $orgId]);
            echo json_encode(['success' => true]);
            break;
        
        // ========== МОИ ОРГАНИЗАЦИИ (для ЛК) ==========
        case 'my_orgs':
            if (!$isAuth) {
                echo json_encode(['success' => false, 'error' => 'Not authorized']);
                exit;
            }
            // Все членства игрока
            $stmt = $pdo->prepare("
                SELECT o.id, o.name, m.role
                FROM memberships m
                JOIN organisations o ON m.organisation_id = o.id
                WHERE m.player_id = ?
                ORDER BY m.joined_at ASC
            ");
            $stmt->execute([$user['id']]);
            $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            // Организация, которой владеет (если есть)
            $stmt2 = $pdo->prepare("SELECT id, name FROM organisations WHERE owner_id = ?");
            $stmt2->execute([$user['id']]);
            $owned = $stmt2->fetch(PDO::FETCH_ASSOC);
    
            echo json_encode([
                'success'      => true,
                'memberships'  => $memberships,
                'owned'        => $owned ?: null,
                'total'        => count($memberships)
            ]);
            break;
        // ========== НАЗНАЧИТЬ / СНЯТЬ ЗАМЕСТИТЕЛЯ ==========
        case 'set_deputy':
            if (!$isAuth) exit;
            if ($method !== 'POST') exit;
            $orgId    = intval($postData['organisation_id'] ?? 0);
            $targetId = intval($postData['player_id'] ?? 0);
            $action   = $postData['deputy_action'] ?? ''; // 'appoint' или 'remove'
    
            $org = getOrg($pdo, $orgId);
            if (!$org || $org['owner_id'] != $user['id']) {
                echo json_encode(['success' => false, 'error' => 'Только владелец может управлять заместителями']);
                exit;
            }
            if ($targetId == $user['id']) {
                echo json_encode(['success' => false, 'error' => 'Нельзя изменить свою роль']);
                exit;
            }
            // Проверить, что игрок состоит в организации
            $stmt = $pdo->prepare("SELECT role FROM memberships WHERE player_id = ? AND organisation_id = ?");
            $stmt->execute([$targetId, $orgId]);
            $mem = $stmt->fetch();
            if (!$mem) {
                echo json_encode(['success' => false, 'error' => 'Игрок не состоит в организации']);
                exit;
            }
            if ($action === 'appoint') {
                if ($mem['role'] === 'owner') {
                    echo json_encode(['success' => false, 'error' => 'Нельзя назначить владельца заместителем']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE memberships SET role = 'deputy' WHERE player_id = ? AND organisation_id = ?");
                $stmt->execute([$targetId, $orgId]);
            } elseif ($action === 'remove') {
                if ($mem['role'] !== 'deputy') {
                    echo json_encode(['success' => false, 'error' => 'Игрок не является заместителем']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE memberships SET role = 'member' WHERE player_id = ? AND organisation_id = ?");
                $stmt->execute([$targetId, $orgId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Неверное действие']);
                exit;
            }
            echo json_encode(['success' => true]);
            break;

    default:
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
}
function canManage($pdo, $orgId, $userId) {
    $org = getOrg($pdo, $orgId);
    if (!$org) return false;
    if ($org['owner_id'] == $userId) return true;
    // Проверить, не является ли заместителем
    $stmt = $pdo->prepare("SELECT role FROM memberships WHERE player_id = ? AND organisation_id = ?");
    $stmt->execute([$userId, $orgId]);
    $mem = $stmt->fetch();
    return $mem && $mem['role'] === 'deputy';
}
// ======================= ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ =======================
function getOrg($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT o.*, u.minecraft_nick as owner_nick
        FROM organisations o
        JOIN users u ON o.owner_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function addNotification($pdo, $playerId, $type, $dataJson) {
    $stmt = $pdo->prepare("INSERT INTO notifications (player_id, type, data) VALUES (?, ?, ?)");
    $stmt->execute([$playerId, $type, $dataJson]);
}