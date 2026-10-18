// js/banner-builder.js
const BANNER_COLORS = {
    white: '#F9FFFE', orange: '#F9801D', magenta: '#C74EBD', light_blue: '#3AB3DA',
    yellow: '#FED83D', lime: '#80C71F', pink: '#F38BAA', gray: '#474F52',
    light_gray: '#9D9D97', cyan: '#169C9C', purple: '#8932B8', blue: '#3C44AA',
    brown: '#835432', green: '#5E7C16', red: '#B02E26', black: '#1D1D21'
};

const COLOR_NAMES = Object.keys(BANNER_COLORS);

const PATTERNS = [
    'border','bricks','circle','creeper','cross','curly_border','diagonal_left',
    'diagonal_right','diagonal_up_left','diagonal_up_right','flow','flower','globe',
    'gradient','gradient_up','guster','half_horizontal','half_horizontal_bottom',
    'half_vertical','half_vertical_right','mojang','piglin','rhombus','skull',
    'small_stripes','square_bottom_left','square_bottom_right','square_top_left',
    'square_top_right','straight_cross','stripe_bottom','stripe_center',
    'stripe_downleft','stripe_downright','stripe_left','stripe_middle','stripe_right',
    'stripe_top','triangle_bottom','triangle_top','triangles_bottom','triangles_top'
];

const BANNER_W = 20;
const BANNER_H = 40;
const SCALE = 8; // предпросмотр 160x320

class BannerBuilder {
    constructor(containerId, initialData = null) {
        this.container = document.getElementById(containerId);
        this.canvas = null;
        this.ctx = null;

        // Состояние
        this.baseColor = initialData?.banner_base_color || 'white';
        this.layers = initialData?.banner_patterns && Array.isArray(initialData.banner_patterns)
            ? [...initialData.banner_patterns]
            : [];

        this.activeLayer = -1;  // -1 = слой 0 (основа), 0+ = слой узора
        this.loadedImages = {};
        this.init();
    }

    init() {
        this.renderUI();
        this.canvas = this.container.querySelector('#bannerCanvas');
        this.ctx = this.canvas.getContext('2d');
        this.ctx.imageSmoothingEnabled = false;
        this.drawBanner();
        this.updateRightPanel(); // <-- вот это вместо this.attachEvents()
    }

    // ─── ОТРИСОВКА UI ────────────────────────────
    renderUI() {
        this.container.innerHTML = `
        <div style="display:flex; gap:2rem; flex-wrap:wrap; align-items:flex-start;" class="banner-builder-flex">
            <!-- ЛЕВО: баннер + выбор основы -->
            <div style="flex:0 0 auto; text-align:center;" class="banner-left-col">
                <canvas id="bannerCanvas" width="${BANNER_W * SCALE}" height="${BANNER_H * SCALE}"
                    style="image-rendering:pixelated; width:${BANNER_W * SCALE}px; height:${BANNER_H * SCALE}px; border:2px solid rgba(34,255,136,0.3); border-radius:6px; background:#1a1a1a; display:block; margin:0 auto 1rem; max-width:100%; height:auto;">
                </canvas>
                <div id="baseColorRow" style="margin-top:0.5rem;"></div>
            </div>
    
            <!-- ПРАВО: слои, узоры, цвет -->
            <div style="flex:1; min-width:0;" class="banner-right-col">
                <!-- Пузырьки слоёв -->
                <div id="layerBubbles" style="display:flex; gap:0.7rem; align-items:center; margin-bottom:1.2rem; flex-wrap:wrap;"></div>
    
                <!-- Сетка узоров (видна только для слоёв 1+) -->
                <div id="patternGrid" style="display:none; margin-bottom:1rem;">
                    <div id="patternGridInner" style="display:flex; flex-wrap:wrap; gap:6px; justify-content:flex-start;"></div>
                </div>
    
                <!-- Выбор цвета для текущего слоя -->
                <div id="layerColorRow" style="display:none; margin-top:0.8rem;"></div>
            </div>
        </div>
        `;
    
        this.renderBubbles();
        this.renderBaseColorRow();
        this.updateRightPanel();
    }

    // ─── ПУЗЫРЬКИ ────────────────────────────────
    renderBubbles() {
        const container = this.container.querySelector('#layerBubbles');
        let html = '';

        // Пузырёк 0 (основа)
        html += this.bubbleHTML(-1, '0', this.activeLayer === -1);

        // Слои узоров
        for (let i = 0; i < this.layers.length; i++) {
            html += this.bubbleHTML(i, String(i + 1), this.activeLayer === i);
        }

        // Кнопка "+"
        if (this.layers.length < 6) {
            html += `<button class="layer-bubble add-bubble" id="addLayerBtn" title="Добавить слой">+</button>`;
        }

        container.innerHTML = html;
        this.attachBubbleEvents();
    }

    bubbleHTML(index, label, isActive) {
        const removeBtn = (index >= 0)
            ? `<span class="bubble-remove" data-index="${index}" title="Удалить слой">&times;</span>`
            : '';
        return `
        <div class="layer-bubble ${isActive ? 'active' : ''}" data-index="${index}" style="position:relative;">
            ${label}
            ${removeBtn}
        </div>`;
    }

    attachBubbleEvents() {
        const self = this;
        this.container.querySelectorAll('.layer-bubble[data-index]').forEach(bubble => {
            bubble.addEventListener('click', function(e) {
                if (e.target.classList.contains('bubble-remove')) return;
                const idx = parseInt(this.dataset.index);
                self.activeLayer = idx;
                self.updateRightPanel();
                self.renderBubbles();
            });
        });

        // Крестики удаления
        this.container.querySelectorAll('.bubble-remove').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const idx = parseInt(this.dataset.index);
                self.layers.splice(idx, 1);
                // Если удалили активный слой, переключиться на основу или предыдущий
                if (self.activeLayer === idx) {
                    self.activeLayer = self.layers.length > 0 ? self.layers.length - 1 : -1;
                } else if (self.activeLayer > idx) {
                    self.activeLayer--;
                }
                self.drawBanner();
                self.renderBubbles();
                self.updateRightPanel();
            });
        });

        // Кнопка "+"
        const addBtn = this.container.querySelector('#addLayerBtn');
        if (addBtn) {
            addBtn.addEventListener('click', () => {
                if (this.layers.length >= 6) return;
                this.layers.push({ pattern: PATTERNS[0], color: 'white' });
                this.activeLayer = this.layers.length - 1;
                this.drawBanner();
                this.renderBubbles();
                this.updateRightPanel();
            });
        }
    }

    // ─── ПРАВАЯ ПАНЕЛЬ ────────────────────────────
    updateRightPanel() {
        const patternGrid = this.container.querySelector('#patternGrid');
        const layerColorRow = this.container.querySelector('#layerColorRow');
        const baseColorRow = this.container.querySelector('#baseColorRow');

        if (this.activeLayer === -1) {
            // Основа
            patternGrid.style.display = 'none';
            layerColorRow.style.display = 'none';
            baseColorRow.style.display = 'block';
            this.renderBaseColorRow();
        } else {
            // Слой узора
            patternGrid.style.display = 'block';
            layerColorRow.style.display = 'block';
            baseColorRow.style.display = 'none';
            this.renderPatternGrid();
            this.renderLayerColorRow();
        }
    }

    // ─── СТРОКА КРАСИТЕЛЕЙ (ОСНОВА) ──────────────
    renderBaseColorRow() {
        const row = this.container.querySelector('#baseColorRow');
        row.innerHTML = `
            <div style="color:#aaa; margin-bottom:0.3rem; font-size:0.85rem;">Цвет основы</div>
            <div style="display:flex; gap:4px; flex-wrap:wrap; justify-content:center;">
                ${COLOR_NAMES.map(c => this.dyeSwatchHTML(c, c === this.baseColor, 'base')).join('')}
            </div>`;

        row.querySelectorAll('.dye-swatch').forEach(sw => {
            sw.addEventListener('click', () => {
                this.baseColor = sw.dataset.color;
                this.drawBanner();
                this.renderBaseColorRow();
            });
        });
    }

    // ─── СТРОКА КРАСИТЕЛЕЙ (СЛОЙ) ─────────────────
    renderLayerColorRow() {
        const row = this.container.querySelector('#layerColorRow');
        if (this.activeLayer < 0 || this.activeLayer >= this.layers.length) return;
        const currentColor = this.layers[this.activeLayer].color;
        row.innerHTML = `
            <div style="color:#aaa; margin-bottom:0.3rem; font-size:0.85rem;">Цвет узора</div>
            <div style="display:flex; gap:4px; flex-wrap:wrap;">
                ${COLOR_NAMES.map(c => this.dyeSwatchHTML(c, c === currentColor, 'layer')).join('')}
            </div>`;

        row.querySelectorAll('.dye-swatch').forEach(sw => {
            sw.addEventListener('click', () => {
                this.layers[this.activeLayer].color = sw.dataset.color;
                this.drawBanner();
                this.renderLayerColorRow();
            });
        });
    }

    // HTML одного квадратика красителя
    dyeSwatchHTML(colorName, isActive, mode) {
        const border = isActive ? '2px solid #22ff88' : '2px solid rgba(255,255,255,0.2)';
        const size = '32px';
        return `<div class="dye-swatch" data-color="${colorName}"
            style="width:${size};height:${size};background:rgba(0,0,0,0.5);border:${border};border-radius:4px;cursor:pointer;image-rendering:pixelated;display:flex;align-items:center;justify-content:center;"
            title="${colorName}">
            <img src="../img/dyes/${colorName}.png" style="width:28px;height:28px;image-rendering:pixelated;">
        </div>`;
    }
    // ─── СЕТКА УЗОРОВ ─────────────────────────────
    renderPatternGrid() {
        const grid = this.container.querySelector('#patternGridInner');
        if (this.activeLayer < 0 || this.activeLayer >= this.layers.length) return;
        const currentPattern = this.layers[this.activeLayer].pattern;

        grid.innerHTML = PATTERNS.map(p => `
            <div class="pattern-cell ${p === currentPattern ? 'active' : ''}" data-pattern="${p}"
                style="width:44px; height:64px; background:rgba(0,0,0,0.5); border:2px solid ${p === currentPattern ? '#22ff88' : 'rgba(34,255,136,0.2)'}; border-radius:6px; display:flex; align-items:center; justify-content:center; cursor:pointer; overflow:hidden;">
                <img src="../img/banners/${p}.png" style="image-rendering:pixelated; width:40px; height:60px; object-fit:contain;" alt="${p}">
            </div>
        `).join('');

        grid.querySelectorAll('.pattern-cell').forEach(cell => {
            cell.addEventListener('click', () => {
                this.layers[this.activeLayer].pattern = cell.dataset.pattern;
                this.drawBanner();
                this.renderPatternGrid();
            });
        });
    }

    // ─── ОТРИСОВКА БАННЕРА ────────────────────────
    async drawBanner() {
        const ctx = this.ctx;
        const w = this.canvas.width;
        const h = this.canvas.height;
        ctx.clearRect(0, 0, w, h);

        // Фон основы
        ctx.fillStyle = BANNER_COLORS[this.baseColor] || '#fff';
        ctx.fillRect(0, 0, w, h);

        // Слои узоров
        for (let layer of this.layers) {
            const img = await this.loadImage(`../img/banners/${layer.pattern}.png`);
            if (!img) continue;
            const offCanvas = document.createElement('canvas');
            offCanvas.width = img.width;
            offCanvas.height = img.height;
            const offCtx = offCanvas.getContext('2d');
            offCtx.imageSmoothingEnabled = false;
            offCtx.drawImage(img, 0, 0);
            offCtx.globalCompositeOperation = 'source-in';
            offCtx.fillStyle = BANNER_COLORS[layer.color] || '#fff';
            offCtx.fillRect(0, 0, offCanvas.width, offCanvas.height);
            ctx.drawImage(offCanvas, 0, 0, w, h);
        }
    }

    loadImage(src) {
        if (this.loadedImages[src]) return Promise.resolve(this.loadedImages[src]);
        return new Promise((resolve) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => { this.loadedImages[src] = img; resolve(img); };
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }

    // ─── ПОЛУЧИТЬ ДАННЫЕ ──────────────────────────
    getData() {
        return {
            banner_base_color: this.baseColor,
            banner_patterns: this.layers,
            banner_image: this.canvas.toDataURL('image/png')
        };
    }
}

// Глобальная функция инициализации
function initBannerBuilder(containerId, initialData) {
    return new BannerBuilder(containerId, initialData);
}