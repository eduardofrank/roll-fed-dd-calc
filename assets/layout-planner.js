/**
 * Roll Fed Calc — Print Layout Planner  v2.19.1
 * Direct-manipulation planner: drag to move, handles to resize, rotate, replace.
 * Prints never overlap. Everything prints at 400 PPI; low-res files are flagged
 * with the largest size they print sharp at 400 PPI, but never blocked.
 * Renders on a persistent skeleton so selecting/dragging never rebuilds the
 * canvas (and never scrolls the page); rebuilds preserve scroll position.
 */
(function () {
    'use strict';

    var TARGET_PPI = 400, CM_PER_IN = 2.54, EPS = 1e-6, MIN_SIDE_IN = 0.25, MAX_STEPPER_CLICKS = 12, NUDGE_IN = 0.1;
    // The press will not feed a job shorter than 279 mm, so a layout under that
    // length still consumes — and is charged — the full minimum. Mirrors
    // FAC_MIN_PRINT_LENGTH_CM in includes/pricing.php.
    var MIN_FEED_CM = 27.9, MIN_FEED_IN = MIN_FEED_CM / CM_PER_IN;

    var DEFAULT_ROLLS = [
        { key: '44', label: '44" Roll', widthInches: 44, usableInches: 43.7, usableCm: 111 },
        { key: '50', label: '50" Roll', widthInches: 50, usableInches: 49.7, usableCm: 126.238 },
        { key: '60', label: '60" Roll', widthInches: 60, usableInches: 59.7, usableCm: 151.638 },
        { key: '64', label: '64" Roll', widthInches: 64, usableInches: 63.7, usableCm: 161.798 }
    ];

    var STRINGS = {
        en: {
            title: 'Print Layout Planner', tag: 'Your artwork on the roll — to scale',
            rollLine: '{roll} roll · {printable} printable width',
            lede: 'Drop in the images you want to print and drag them onto the roll at true size. Drag a corner to resize, or type an exact size in the bar above the roll. Double-click to swap a picture. Select several with Shift-click or by dragging a box around them, then move, scale, rotate or duplicate them together. Prints never overlap, and the calculator quantity updates with every print you add. Everything prints at {ppi} PPI.',
            addImages: 'Add images', arrange: 'Arrange to fit', clearAll: 'Clear all',
            addPlaceholder: 'Add placeholder',
            placeholderName: 'Placeholder',
            placeholderNote: '{n} placeholder(s) in your layout.',
            placeholderBody: 'These reserve space and are priced normally. Send the artwork through the WeTransfer link after checkout and we will drop each file into its place.',
            prints: 'Prints planned', feedUsed: 'Roll length used', utilization: 'Width used', widestGap: 'Widest free strip',
            syncedQty: 'Calculator quantity synced', none: 'None',
            dropHere: 'Drop images here or click to browse', dropSub: 'JPG · PNG · WebP — placed at true print size',
            legendPrintable: 'Printable area', legendEdge: 'Non-printable roll edge', legendOff: 'Off-roll (not printed)',
            printableLabel: '{w} printable width', edgeLabel: 'edge',
            feedUsedMark: 'Roll length used: {len}', unusedRoll: 'Roll continues as you add prints ↓',
            units: 'Units', unitIn: 'Inches', unitCm: 'Centimeters', swapDims: 'Swap width and height',
            lowResFlag: 'LOW RES', aspectLock: 'Lock aspect ratio', aspectUnlock: 'Unlock aspect ratio — set width and height independently', rotate: 'Rotate 90°',
            duplicate: 'Duplicate', replace: 'Replace image', remove: 'Remove', width: 'W', height: 'H',
            widthFull: 'Width', heightFull: 'Height', exprHint: 'Press Enter to work this out',
            maxAt400: 'Max {dims} at {ppi} PPI',
            lowResSummary: '{n} image(s) are lower resolution than {ppi} PPI at their current size.',
            lowResSummaryBody: 'Each one prints sharp at {ppi} PPI up to the size shown on it. You can print larger — detail just gets softer — and they stay in your order. For the crispest result, send higher-resolution masters through the WeTransfer link after checkout.',
            mixedTitle: 'You planned {n} different sizes.',
            mixedBody: 'The calculator prices one size at a time. Apply a size group below, add it to cart, then apply the next group and repeat.',
            applyGroup: 'Apply {dims}', applied: 'In calculator',
            qtyMismatch: 'Calculator quantity is {qty}, but {n} print(s) are planned.', syncNow: 'Sync quantity',
            priceLabel: 'Your price',
            wasteTitlePriced: 'Your arrangement is using {extra} more roll than these prints need.',
            wasteBodyPriced: 'Paper is charged by the roll length a job runs, so spreading the prints out costs more than nesting them. {arrange} packs them into {nest} instead of {used} — same prints, less paper.',
            wasteTitleFree: 'Your arrangement is using {extra} more roll than these prints need.',
            wasteBodyFree: '{arrange} packs them into {nest} instead of {used} — same prints, and the studio prints exactly what you placed.',
            wasteArrangeName: '“Arrange to fit”',
            feedChargedSub: 'nested: {nest}',
            minFeedTitle: 'Your layout is shorter than the {min} the printer feeds.',
            minFeedBody: 'The press cannot advance the roll less than {min} ({minmm} mm) for a job, so that length is used and priced whether or not your prints cover it. You have {free} of roll length still paid for — add or enlarge prints to fill it at no extra paper cost.',
            minFeedStatSub: '{min} minimum billed',
            leverageTip: 'Tip: {len} of printable width is still free next to your last row — duplicate a print or size one up to use it.',
            decodeError: 'Could not read “{name}” in the browser. Use a JPG or PNG preview for planning — you can still send the print master after checkout.',
            planningOnly: 'Your arranged layout is saved with your order so the studio prints exactly what you placed. Everything prints at {ppi} PPI; for the sharpest result, send your full-resolution files through the WeTransfer link after checkout.',
            selPrompt: 'Select a print to edit its size — Shift-click or drag a box to select several.',
            selCountOne: '1 print selected', selCountMany: '{n} prints selected',
            scaleLabel: 'Scale', scaleDown: 'Scale down 10%', scaleUp: 'Scale up 10%', deselect: 'Deselect all',
            groupHint: 'Drag any selected print to move them together · drag a corner to scale.',
            uploadingNow: 'Uploading {n} image(s) to your order… large files can take a few minutes. Please wait before checking out.',
            uploadingPct: 'Uploading “{name}” — {pct}% of this file sent. {n} image(s) to go. Large masters take a few minutes; leave this page open.',
            checkoutHeld: 'Your artwork is still uploading.',
            checkoutHeldBody: 'Adding to cart now would place the order without the images that have not finished. The button works again the moment the upload completes.',
            uploadFailed: '{n} image(s) could not be uploaded to your order.',
            uploadFailedBody: 'They are still shown in your layout and will be priced, but the studio will not receive the files. Try removing and re-adding them, or send them through the WeTransfer link after checkout.',
            in: 'in', cm: 'cm'
        },
        es: {
            title: 'Planificador de impresión', tag: 'Tu obra sobre el rollo — a escala',
            rollLine: 'Rollo de {roll} · {printable} de ancho imprimible',
            lede: 'Arrastra las imágenes que quieres imprimir sobre el rollo a tamaño real. Arrastra una esquina para redimensionar, o escribe un tamaño exacto en la barra sobre el rollo. Haz doble clic para reemplazar una imagen. Selecciona varias con Shift-clic o dibujando un recuadro alrededor, y luego muévelas, escálalas, rótalas o duplícalas juntas. Las impresiones nunca se superponen, y la cantidad del calculador se actualiza con cada impresión. Todo se imprime a {ppi} PPI.',
            addImages: 'Añadir imágenes', arrange: 'Ordenar para ajustar', clearAll: 'Vaciar todo',
            addPlaceholder: 'Añadir marcador',
            placeholderName: 'Marcador',
            placeholderNote: '{n} marcador(es) en tu composición.',
            placeholderBody: 'Reservan el espacio y se cobran con normalidad. Envía las imágenes por el enlace de WeTransfer tras el pago y las colocaremos en su sitio.',
            prints: 'Impresiones', feedUsed: 'Largo de rollo usado', utilization: 'Ancho usado', widestGap: 'Mayor franja libre',
            syncedQty: 'Cantidad sincronizada', none: 'Ninguna',
            dropHere: 'Suelta imágenes aquí o haz clic para elegirlas', dropSub: 'JPG · PNG · WebP — a tamaño real de impresión',
            legendPrintable: 'Área imprimible', legendEdge: 'Borde no imprimible', legendOff: 'Fuera del rollo (no se imprime)',
            printableLabel: '{w} de ancho imprimible', edgeLabel: 'borde',
            feedUsedMark: 'Largo usado: {len}', unusedRoll: 'El rollo continúa al añadir impresiones ↓',
            units: 'Unidades', unitIn: 'Pulgadas', unitCm: 'Centímetros', swapDims: 'Intercambiar ancho y alto',
            lowResFlag: 'BAJA RES.', aspectLock: 'Bloquear proporción', aspectUnlock: 'Desbloquear proporción: ancho y alto independientes', rotate: 'Rotar 90°',
            duplicate: 'Duplicar', replace: 'Reemplazar imagen', remove: 'Quitar', width: 'An', height: 'Al',
            widthFull: 'Ancho', heightFull: 'Alto', exprHint: 'Pulsa Intro para calcular',
            maxAt400: 'Máx {dims} a {ppi} PPI',
            lowResSummary: '{n} imagen(es) tienen menos resolución que {ppi} PPI a su tamaño actual.',
            lowResSummaryBody: 'Cada una imprime nítida a {ppi} PPI hasta el tamaño indicado en ella. Puedes imprimir más grande — el detalle solo se suaviza — y permanecen en tu pedido. Para el mejor resultado, envía archivos de mayor resolución por WeTransfer tras el pago.',
            mixedTitle: 'Planificaste {n} tamaños distintos.',
            mixedBody: 'El calculador cotiza un tamaño a la vez. Aplica un grupo abajo, añádelo al carrito y luego aplica el siguiente.',
            applyGroup: 'Aplicar {dims}', applied: 'En el calculador',
            qtyMismatch: 'La cantidad del calculador es {qty}, pero hay {n} impresión(es) planificadas.', syncNow: 'Sincronizar cantidad',
            priceLabel: 'Tu precio',
            wasteTitlePriced: 'Tu composición usa {extra} más de rollo del que necesitan estas impresiones.',
            wasteBodyPriced: 'El papel se cobra por el largo de rollo que avanza el trabajo, así que separarlas cuesta más que agruparlas. {arrange} las agrupa en {nest} en vez de {used} — las mismas impresiones, menos papel.',
            wasteTitleFree: 'Tu composición usa {extra} más de rollo del que necesitan estas impresiones.',
            wasteBodyFree: '{arrange} las agrupa en {nest} en vez de {used} — las mismas impresiones, y el estudio imprime exactamente lo que colocaste.',
            wasteArrangeName: '«Ajustar composición»',
            feedChargedSub: 'agrupadas: {nest}',
            minFeedTitle: 'Tu composición es más corta que los {min} que avanza la impresora.',
            minFeedBody: 'La impresora no puede avanzar el rollo menos de {min} ({minmm} mm) por trabajo, así que ese largo se consume y se cobra aunque tus impresiones no lo cubran. Te quedan {free} de rollo ya pagados — añade o amplía impresiones para aprovecharlos sin coste de papel adicional.',
            minFeedStatSub: 'mínimo de {min} facturado',
            leverageTip: 'Consejo: quedan {len} de ancho imprimible junto a tu última fila — duplica una impresión o amplía una para aprovecharlos.',
            decodeError: 'No se pudo leer «{name}» en el navegador. Usa una copia JPG o PNG para planificar — el archivo final se envía tras el pago.',
            planningOnly: 'Tu composición se guarda con el pedido para que el estudio imprima exactamente lo que colocaste. Todo se imprime a {ppi} PPI; para el mejor resultado, envía tus archivos de alta resolución por WeTransfer tras el pago.',
            selPrompt: 'Selecciona una impresión para editar su tamaño — Shift-clic o dibuja un recuadro para elegir varias.',
            selCountOne: '1 impresión seleccionada', selCountMany: '{n} impresiones seleccionadas',
            scaleLabel: 'Escala', scaleDown: 'Reducir 10%', scaleUp: 'Ampliar 10%', deselect: 'Quitar selección',
            groupHint: 'Arrastra cualquier impresión seleccionada para moverlas juntas · arrastra una esquina para escalar.',
            uploadingNow: 'Subiendo {n} imagen(es) a tu pedido… los archivos grandes pueden tardar unos minutos. Espera antes de pagar.',
            uploadingPct: 'Subiendo «{name}» — {pct}% enviado. Faltan {n} imagen(es). Los archivos grandes tardan unos minutos; deja esta página abierta.',
            checkoutHeld: 'Tus imágenes todavía se están subiendo.',
            checkoutHeldBody: 'Añadir al carrito ahora dejaría el pedido sin las imágenes que no han terminado. El botón vuelve a funcionar en cuanto termine la subida.',
            uploadFailed: 'No se pudieron subir {n} imagen(es) a tu pedido.',
            uploadFailedBody: 'Siguen en tu composición y se cotizan, pero el estudio no recibirá los archivos. Prueba a quitarlas y volver a añadirlas, o envíalas por el enlace de WeTransfer tras el pago.',
            in: 'in', cm: 'cm'
        }
    };
    var lang = (window.__FAC_LANG === 'es') ? 'es' : 'en';
    function t(key, vars) {
        var s = (STRINGS[lang] && STRINGS[lang][key]) || STRINGS.en[key] || key;
        if (vars) Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
        return s;
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'className') node.className = attrs[k];
            else if (k === 'text') node.textContent = attrs[k];
            else if (k === 'html') node.innerHTML = attrs[k];
            else if (k === 'style') node.setAttribute('style', attrs[k]);
            else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') node.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] !== null && attrs[k] !== undefined) node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(c); });
        return node;
    }
    function svgIcon(pathD, cls) {
        var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
        s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
        if (cls) s.setAttribute('class', cls);
        pathD.split('|').forEach(function (d) { var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); s.appendChild(p); });
        return s;
    }
    var ICONS = {
        upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4|M17 8l-5-5-5 5|M12 3v12',
        arrange: 'M3 3h7v7H3z|M14 3h7v4h-7z|M14 11h7v10h-7z|M3 14h7v7H3z',
        placeholder: 'M3 5h18v14H3z|M12 9v6|M9 12h6',
        // The swap control from the original dimensions row, kept exactly:
        // lucide refresh-cw, turning 180deg on hover over 300ms.
        swapDims: 'M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8|M21 3v5h-5|M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16|M8 16H3v5',
        lockOn: 'M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2z|M7 11V7a5 5 0 0 1 10 0v4',
        lockOff: 'M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2z|M7 11V7a5 5 0 0 1 9.9-1',
        rotate: 'M23 4v6h-6|M20.49 15a9 9 0 1 1-2.12-9.36L23 10',
        copy: 'M20 9H11a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2z|M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
        swap: 'M21 12a9 9 0 0 1-9 9|M3 12a9 9 0 0 1 9-9|M12 3l3 3-3 3|M12 21l-3-3 3-3',
        trash: 'M3 6h18|M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6|M10 11v6|M14 11v6',
        warn: 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z|M12 9v4|M12 17h.01',
        info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z|M12 16v-4|M12 8h.01',
        error: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z|M15 9l-6 6|M9 9l6 6',
        scale: 'M15 3h6v6|M9 21H3v-6|M21 3l-7 7|M3 21l7-7',
        close: 'M18 6 6 18|M6 6l12 12',
        layers: 'M12 2 2 7l10 5 10-5-10-5z|M2 17l10 5 10-5|M2 12l10 5 10-5'
    };
    function fmtNum(n, maxDec) { if (maxDec === undefined) maxDec = 2; return n.toFixed(maxDec).replace(/\.?0+$/, ''); }
    /**
     * Evaluate a short arithmetic expression typed into a size field.
     *
     * Print sizes are often arrived at rather than known — "8+2" for a two inch
     * border, "36/2" to halve something. Handles + - * / and parentheses with
     * the usual precedence, tolerates a unit written alongside the number, and
     * returns NaN on anything it cannot read end to end, so a typo leaves the
     * print alone rather than resizing it to something arbitrary.
     *
     * Deliberately a small parser rather than eval(): field contents are user
     * input and must never be executed.
     */
    function evalExpr(str) {
        var src = String(str)
            .replace(/(inches|inch|in|cm|mm)\b/gi, '')   // 8in + 2in
            .replace(/["”“'′]/g, '')                     // 8" + 2"
            .replace(/[×✕]/g, '*').replace(/÷/g, '/')
            .replace(/,/g, '.')
            .trim();
        if (!src || !/^[0-9.+\-*/()\s]+$/.test(src)) return NaN;

        var i = 0;
        function ws() { while (i < src.length && /\s/.test(src.charAt(i))) i++; }
        function factor() {
            ws();
            var c = src.charAt(i);
            if (c === '+') { i++; return factor(); }
            if (c === '-') { i++; var neg = factor(); return isNaN(neg) ? NaN : -neg; }
            if (c === '(') {
                i++;
                var inner = expression();
                ws();
                if (src.charAt(i) !== ')') return NaN;
                i++;
                return inner;
            }
            var start = i;
            while (i < src.length && /[0-9.]/.test(src.charAt(i))) i++;
            if (i === start) return NaN;
            var n = parseFloat(src.slice(start, i));
            return isFinite(n) ? n : NaN;
        }
        function term() {
            var v = factor();
            for (;;) {
                if (isNaN(v)) return NaN;
                ws();
                var c = src.charAt(i);
                if (c === '*') { i++; v *= factor(); }
                else if (c === '/') { i++; var d = factor(); if (isNaN(d) || 0 === d) return NaN; v /= d; }
                else return v;
            }
        }
        function expression() {
            var v = term();
            for (;;) {
                if (isNaN(v)) return NaN;
                ws();
                var c = src.charAt(i);
                if (c === '+') { i++; v += term(); }
                else if (c === '-') { i++; v -= term(); }
                else return v;
            }
        }
        var out = expression();
        ws();
        if (i !== src.length) return NaN;      // trailing junk means we misread it
        return isFinite(out) ? out : NaN;
    }
    /*
     * No lenient fallback here on purpose. Taking the leading number out of
     * something malformed turns "8+" — a half-typed expression — into 8, and
     * silently resizes the print. NaN instead leaves the size exactly as it was,
     * which is what the caller does with it.
     */
    function parseLen(str) { return evalExpr(str); }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    function toDisplay(inches, units) { return units === 'centimeters' ? inches * CM_PER_IN : inches; }
    function fromDisplay(value, units) { return units === 'centimeters' ? value / CM_PER_IN : value; }
    function unitSuffix(units) { return units === 'centimeters' ? t('cm') : t('in'); }
    function fmtLen(inches, units, dec) { if (dec === undefined) dec = units === 'centimeters' ? 1 : 2; return fmtNum(toDisplay(inches, units), dec) + ' ' + unitSuffix(units); }
    function fmtDims(wIn, hIn, units) { var dec = units === 'centimeters' ? 1 : 2; return fmtNum(toDisplay(wIn, units), dec) + ' × ' + fmtNum(toDisplay(hIn, units), dec) + ' ' + unitSuffix(units); }

    function effectivePpi(pxW, pxH, wIn, hIn) { if (!(wIn > 0) || !(hIn > 0) || !(pxW > 0) || !(pxH > 0)) return 0; return Math.min(pxW / wIn, pxH / hIn); }
    function footprintPixels(it) { return (it.rotation === 90 || it.rotation === 270) ? { px: it.pxH, py: it.pxW } : { px: it.pxW, py: it.pxH }; }
    function itemPpi(it) { var m = footprintPixels(it); return effectivePpi(m.px, m.py, it.wIn, it.hIn); }
    function isLowRes(it) { if (it.placeholder) return false; var p = itemPpi(it); return p > 0 && p < TARGET_PPI - 0.5; }
    function maxSizeAt400(it) { var m = footprintPixels(it); return { wIn: m.px / TARGET_PPI, hIn: m.py / TARGET_PPI }; }
    function footprintAspect(it) { var m = footprintPixels(it); return m.px / m.py; }
    /**
     * The ratio a resize should hold, or 0 for "size the sides independently".
     *
     * A real photograph is always kept in proportion — printing one stretched is
     * never what anyone meant. A placeholder is reserved space, so it is free by
     * default and holds whatever shape it currently has once the shopper locks
     * it, rather than snapping back to the 3:2 it was created at.
     */
    function aspectRatioFor(it) {
        if (!it.placeholder) return footprintAspect(it);
        if (!it.aspectLocked) return 0;
        return (it.wIn > 0 && it.hIn > 0) ? (it.wIn / it.hIn) : footprintAspect(it);
    }
    function toggleAspectLock(id) {
        var it = findItem(id); if (!it) return;
        it.aspectLocked = !it.aspectLocked;
        afterItemsChanged(false);
    }

    function pack(items, usableIn, opts) {
        opts = opts || {}; var prepared = [], oversize = [];
        items.forEach(function (it) {
            var base = it.rotated ? { across: it.hIn, along: it.wIn, rotated: true } : { across: it.wIn, along: it.hIn, rotated: false };
            var candidates = [base];
            if (opts.autoRotate) candidates.push({ across: base.along, along: base.across, rotated: !base.rotated });
            var fitting = candidates.filter(function (c) { return c.across <= usableIn + EPS; });
            if (!fitting.length) { oversize.push(it.id); return; }
            fitting.sort(function (a, b) { return (a.along - b.along) || (b.across - a.across); });
            prepared.push({ id: it.id, across: fitting[0].across, along: fitting[0].along, rotated: fitting[0].rotated });
        });
        if (opts.autoArrange) prepared.sort(function (a, b) { return (b.along - a.along) || (b.across - a.across); });
        var shelves = [];
        prepared.forEach(function (p) {
            var target = null;
            for (var s = 0; s < shelves.length; s++) if (usableIn - shelves[s].used >= p.across - EPS) { target = shelves[s]; break; }
            if (!target) { target = { used: 0, height: 0, placed: [] }; shelves.push(target); }
            target.placed.push({ id: p.id, x: target.used, across: p.across, along: p.along, rotated: p.rotated });
            target.used += p.across; if (p.along > target.height) target.height = p.along;
        });
        var y = 0, area = 0;
        shelves.forEach(function (s) { s.y = y; s.leftover = Math.max(0, usableIn - s.used); y += s.height; s.placed.forEach(function (pl) { area += pl.across * pl.along; }); });
        return { shelves: shelves, totalFeedIn: y, placedCount: prepared.length, oversize: oversize, utilization: y > 0 ? area / (usableIn * y) : 0, maxLeftover: shelves.reduce(function (m, s) { return Math.max(m, s.leftover); }, 0) };
    }
    function computeArrange(items, usableIn) {
        var packed = pack(items.map(function (it) { return { id: it.id, wIn: it.wIn, hIn: it.hIn, rotated: false }; }), usableIn, { autoArrange: true, autoRotate: false });
        var pos = {}; packed.shelves.forEach(function (sh) { sh.placed.forEach(function (pl) { pos[pl.id] = { x: pl.x, y: sh.y }; }); });
        return { pos: pos, feedIn: packed.totalFeedIn, oversize: packed.oversize };
    }
    function computeMove(s, dxIn, dyIn, usableIn) { return { x: clamp(s.x + dxIn, 0, Math.max(0, usableIn - s.w)), y: Math.max(0, s.y + dyIn), w: s.w, h: s.h }; }
    function computeResize(handle, s, pxIn, pyIn, ratio, locked, usableIn) {
        var right = s.x + s.w, bottom = s.y + s.h, nx = s.x, ny = s.y, nw = s.w, nh = s.h;
        var hasE = handle.indexOf('e') >= 0, hasW = handle.indexOf('w') >= 0, hasN = handle.indexOf('n') >= 0, hasS = handle.indexOf('s') >= 0;
        if (hasE) nw = pxIn - s.x;
        if (hasW) { nw = right - pxIn; nx = pxIn; }
        if (hasS) nh = pyIn - s.y;
        if (hasN) { nh = bottom - pyIn; ny = pyIn; }
        nw = Math.max(MIN_SIDE_IN, nw); nh = Math.max(MIN_SIDE_IN, nh);
        if (locked && ratio > 0) {
            var widthDriven = (hasE || hasW) && !(hasN || hasS) ? true : (hasN || hasS) && !(hasE || hasW) ? false : true;
            if (widthDriven) nh = nw / ratio; else nw = nh * ratio;
            if (hasW) nx = right - nw; if (hasN) ny = bottom - nh;
        }
        if (nw > usableIn) { nw = usableIn; if (locked && ratio > 0) nh = nw / ratio; if (hasW) nx = right - nw; if (locked && hasN) ny = bottom - nh; }
        if (nx < 0) { if (hasW) { nw = right; if (locked && ratio > 0) nh = nw / ratio; } nx = 0; if (locked && hasN) ny = bottom - nh; }
        if (nx + nw > usableIn) { if (hasE) { nw = usableIn - nx; if (locked && ratio > 0) nh = nw / ratio; } else nx = usableIn - nw; }
        if (ny < 0) { if (hasN) { nh = bottom; if (locked && ratio > 0) nw = nh * ratio; } ny = 0; }
        nw = Math.max(MIN_SIDE_IN, Math.min(nw, usableIn)); nh = Math.max(MIN_SIDE_IN, nh);
        nx = clamp(nx, 0, Math.max(0, usableIn - nw)); ny = Math.max(0, ny);
        return { x: nx, y: ny, w: nw, h: nh };
    }

    function groupBounds(list) {
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        list.forEach(function (it) { minX = Math.min(minX, it.xIn); minY = Math.min(minY, it.yIn); maxX = Math.max(maxX, it.xIn + it.wIn); maxY = Math.max(maxY, it.yIn + it.hIn); });
        if (!list.length) { minX = minY = maxX = maxY = 0; }
        return { minX: minX, minY: minY, maxX: maxX, maxY: maxY, w: maxX - minX, h: maxY - minY, cx: (minX + maxX) / 2, cy: (minY + maxY) / 2 };
    }
    function overlapsList(x, y, w, h, list) { for (var j = 0; j < list.length; j++) { var it = list[j]; if (rectsOverlap(x, y, w, h, it.xIn, it.yIn, it.wIn, it.hIn)) return true; } return false; }
    // Rigid translate of a selected group. dx is clamped to keep the group's
    // bounding box on the roll; the move is rejected (per axis, like a single
    // print) if it would collide with an unselected print.
    function computeGroupTranslate(sel, others, dxIn, dyIn, usableIn) {
        var b = groupBounds(sel);
        var cdx = clamp(dxIn, -b.minX, Math.max(0, usableIn - b.maxX));
        var cdy = Math.max(dyIn, -b.minY);
        function attempt(dx, dy) {
            var out = {};
            for (var i = 0; i < sel.length; i++) {
                var it = sel[i], nx = it.xIn + dx, ny = it.yIn + dy;
                if (overlapsList(nx, ny, it.wIn, it.hIn, others)) return null;
                out[it.id] = { x: nx, y: ny };
            }
            return out;
        }
        return attempt(cdx, cdy) || attempt(cdx, 0) || attempt(0, cdy) || attempt(0, 0);
    }
    // Uniform scale of a group about an anchor point (gaps scale too, so a
    // non-overlapping group stays non-overlapping). The factor is clamped to
    // keep every print >= MIN_SIDE, keep the group on the roll, and (when
    // growing) avoid collisions with unselected prints.
    function computeGroupScaleAbout(sel, others, factor, cx, cy, usableIn) {
        var b0 = groupBounds(sel), minSide = Infinity, maxW = 0;
        sel.forEach(function (it) { minSide = Math.min(minSide, it.wIn, it.hIn); maxW = Math.max(maxW, it.wIn); });
        // Factor is bounded only by: smallest side >= MIN_SIDE_IN, and the whole
        // group (and every print) fitting across the roll. Edge contact does NOT
        // block growth — instead the scaled group is re-seated onto the roll.
        var fMin = MIN_SIDE_IN / Math.max(minSide, EPS), fMax = Infinity;
        if (b0.w > EPS) fMax = Math.min(fMax, usableIn / b0.w);
        if (maxW > EPS) fMax = Math.min(fMax, usableIn / maxW);
        var f = clamp(factor, fMin, Math.max(fMin, fMax));
        function placeAndSeat(fac) {
            var out = {}, minX = Infinity, minY = Infinity, maxX = -Infinity;
            sel.forEach(function (it) {
                var o = { x: cx + (it.xIn - cx) * fac, y: cy + (it.yIn - cy) * fac, w: it.wIn * fac, h: it.hIn * fac };
                out[it.id] = o; minX = Math.min(minX, o.x); minY = Math.min(minY, o.y); maxX = Math.max(maxX, o.x + o.w);
            });
            var shiftX = 0; if (minX < 0) shiftX = -minX; else if (maxX > usableIn) shiftX = usableIn - maxX;
            var shiftY = (minY < 0) ? -minY : 0;
            if (shiftX || shiftY) { for (var id in out) { out[id].x += shiftX; out[id].y += shiftY; } }
            return out;
        }
        function collides(map) { for (var id in map) { var p = map[id]; if (overlapsList(p.x, p.y, p.w, p.h, others)) return true; } return false; }
        var pos = placeAndSeat(f);
        if (f > 1 && collides(pos)) {
            var lo = 1, hi = f;
            for (var k = 0; k < 20; k++) { var mid = (lo + hi) / 2; if (collides(placeAndSeat(mid))) hi = mid; else lo = mid; }
            f = lo; pos = placeAndSeat(f);
        }
        return { factor: f, pos: pos };
    }

    function getRolls() {
        var raw = window.__FAC_ROLL_WIDTHS;
        if (!Array.isArray(raw)) return DEFAULT_ROLLS;
        var rolls = raw.map(function (r) { return { key: String((r && r.key) || ''), label: String((r && r.label) || ''), widthInches: Number(r && r.widthInches) || 0, usableInches: Number(r && r.usableInches) || 0, usableCm: Number(r && r.usableCm) || 0 }; })
            .filter(function (r) { return r.key && r.usableInches > 0 && r.widthInches > 0; });
        return rolls.length ? rolls : DEFAULT_ROLLS;
    }

    var bridge = {
        root: null, internalWrite: false,
        readRollKey: function () { var b = this.root && this.root.querySelector('.fac__roll-btn--selected'); if (b && b.id && b.id.indexOf('roll-btn-') === 0) return b.id.slice('roll-btn-'.length); return null; },
        readUnits: function () { var l = this.root && this.root.querySelector('.fac__input-unit-label'); if (l && l.textContent.indexOf('(cm)') !== -1) return 'centimeters'; return 'inches'; },
        /*
         * Units belong to the calculator — price and the dimension fields read
         * from it — so the planner's control drives the calculator's own toggle
         * rather than keeping a second, divergent setting.
         */
        setUnits: function (units) {
            if (!this.root) return false;
            var want = units === 'centimeters' ? 'centimeters' : 'inches';
            if (this.readUnits() === want) return true;
            var label = want === 'centimeters' ? 'centimeters' : 'inches';
            var btns = this.root.querySelectorAll('.fac__unit-btn'), i, txt;
            for (i = 0; i < btns.length; i++) {
                txt = (btns[i].textContent || '').trim().toLowerCase();
                if (txt === label) { btns[i].click(); return true; }
            }
            // Fall back to position if the copy has been translated.
            if (btns.length >= 2) { btns[want === 'centimeters' ? 1 : 0].click(); return true; }
            return false;
        },
        readQty: function () { var i = document.getElementById('quantity-input-field'); if (!i) return null; var v = parseInt(i.value, 10); return isFinite(v) ? v : null; },
        setInput: function (input, value) {
            if (!input) return;
            var desc = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
            this.internalWrite = true;
            try { desc.set.call(input, String(value)); input.dispatchEvent(new Event('input', { bubbles: true })); }
            finally { var self = this; setTimeout(function () { self.internalWrite = false; }, 0); }
        },
        syncQuantity: function (target) {
            target = Math.max(1, Math.round(target));
            var current = this.readQty(); if (current === null || current === target) return;
            var delta = target - current, btn = document.getElementById(delta > 0 ? 'quantity-increase-btn' : 'quantity-decrease-btn');
            if (btn && Math.abs(delta) <= MAX_STEPPER_CLICKS) {
                this.internalWrite = true;
                for (var k = 0; k < Math.abs(delta); k++) btn.click();
                var self = this;
                setTimeout(function () { self.internalWrite = false; if (self.readQty() !== target) self.setInput(document.getElementById('quantity-input-field'), target); }, 0);
            } else this.setInput(document.getElementById('quantity-input-field'), target);
        },
        /**
         * Make the calculator re-price without changing anything about the print.
         *
         * Dragging alters no calculator input, so the render that computes the
         * price has no reason to run. Re-writing the width field with the value
         * it already holds looks like it should do it — but React keeps a
         * tracker on the node and swallows any input event whose value it
         * believes it already has, so that event never reaches the handler and
         * the price sat still. Resetting the tracker first is what makes the
         * change register. The field ends up holding exactly what it held
         * before; only the render is new.
         */
        forceRecalc: function () {
            var input = document.getElementById('dimension-width-input');
            if (!input) return;
            var desc = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
            if (!desc || !desc.set) return;
            var v = input.value, self = this;
            this.internalWrite = true;
            try {
                var tracker = input._valueTracker;
                if (tracker && typeof tracker.setValue === 'function') {
                    tracker.setValue(v + '\u0000');
                    desc.set.call(input, v);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                } else {
                    // No tracker to reset (a React the bundle was not built with):
                    // go out and back instead. A trailing space parses the same.
                    desc.set.call(input, v + ' ');
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    desc.set.call(input, v);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } catch (err) { /* leave the price as it stands rather than break the planner */ }
            finally { window.setTimeout(function () { self.internalWrite = false; }, 0); }
        },
        /*
         * The price is the calculator's to compute — mirroring its rendered
         * figure keeps one source of truth. Reading it rather than recomputing
         * it here means the planner can never quote a number the calculator and
         * the server disagree with.
         */
        readPrice: function () {
            var scope = this.root || document;
            var err = scope.querySelector('.fac__summary-error');
            if (err) return { error: true, text: (err.textContent || '').trim() };
            var p = scope.querySelector('.fac__summary-price');
            if (!p) return null;
            var per = scope.querySelector('.fac__summary-per-unit');
            return {
                error: false,
                text: (p.textContent || '').trim(),
                per: per ? (per.textContent || '').trim() : ''
            };
        },
        applyDims: function (wIn, hIn, units) {
            this.setInput(document.getElementById('dimension-width-input'), fmtNum(toDisplay(wIn, units), 2));
            this.setInput(document.getElementById('dimension-height-input'), fmtNum(toDisplay(hIn, units), 2));
        }
    };

    var state = { items: [], selectedIds: [], appliedGroupKey: null, rollKey: null, units: 'inches', dragging: false, marquee: false };
    var nextId = 1, srcRefs = Object.create(null), nodeById = Object.create(null), dom = {};

    function isSelected(id) { return state.selectedIds.indexOf(id) !== -1; }
    function selectedItems() { return state.selectedIds.map(findItem).filter(Boolean); }
    function primarySelected() { return state.selectedIds.length ? findItem(state.selectedIds[state.selectedIds.length - 1]) : null; }
    function setSelection(ids) {
        var seen = Object.create(null), clean = [];
        ids.forEach(function (id) { if (!seen[id] && findItem(id)) { seen[id] = 1; clean.push(id); } });
        state.selectedIds = clean; applySelectionDecorations(); updateSelbar();
    }
    function selectOnly(id) { setSelection(id === null || id === undefined ? [] : [id]); }
    function toggleSelected(id) { var next = state.selectedIds.slice(), i = next.indexOf(id); if (i === -1) next.push(id); else next.splice(i, 1); setSelection(next); }
    function clearSelection() { setSelection([]); }

    function currentRoll() { var r = getRolls(); for (var j = 0; j < r.length; j++) if (r[j].key === state.rollKey) return r[j]; return r[0]; }
    function retainSrc(src) { srcRefs[src] = (srcRefs[src] || 0) + 1; }
    function releaseSrc(src) { if (!srcRefs[src]) return; srcRefs[src] -= 1; if (srcRefs[src] <= 0) { delete srcRefs[src]; if (src.indexOf('blob:') === 0) URL.revokeObjectURL(src); } }
    function findItem(id) { for (var j = 0; j < state.items.length; j++) if (state.items[j].id === id) return state.items[j]; return null; }

    function defaultSizeFor(pxW, pxH) {
        var roll = currentRoll(), wIn = pxW / TARGET_PPI, hIn = pxH / TARGET_PPI;
        if (wIn > roll.usableInches) { var f = roll.usableInches / wIn; wIn = roll.usableInches; hIn *= f; }
        if (wIn < 1 || hIn < 1) { var up = 1 / Math.min(wIn, hIn); wIn = Math.min(roll.usableInches, wIn * up); hIn *= up; }
        return { wIn: Math.max(MIN_SIDE_IN, wIn), hIn: Math.max(MIN_SIDE_IN, hIn) };
    }

    function rectsOverlap(ax, ay, aw, ah, bx, by, bw, bh) { return ax < bx + bw - EPS && bx < ax + aw - EPS && ay < by + bh - EPS && by < ay + ah - EPS; }
    function overlapsAny(x, y, w, h, exceptId) {
        for (var j = 0; j < state.items.length; j++) { var it = state.items[j]; if (it.id === exceptId) continue; if (rectsOverlap(x, y, w, h, it.xIn, it.yIn, it.wIn, it.hIn)) return true; }
        return false;
    }
    function uniqSort(arr) { arr.sort(function (a, b) { return a - b; }); var out = []; for (var i = 0; i < arr.length; i++) if (!out.length || Math.abs(out[out.length - 1] - arr[i]) > EPS) out.push(arr[i]); return out; }
    function autoPlace(item) {
        var usable = currentRoll().usableInches;
        if (item.wIn > usable) { var f = usable / item.wIn; item.wIn = usable; item.hIn *= f; }
        if (!state.items.length || (state.items.length === 1 && state.items[0].id === item.id)) { item.xIn = 0; item.yIn = 0; return; }
        var xs = [0], ys = [0];
        state.items.forEach(function (it) { if (it.id === item.id) return; xs.push(Math.round((it.xIn + it.wIn) * 100) / 100); ys.push(Math.round(it.yIn * 100) / 100); ys.push(Math.round((it.yIn + it.hIn) * 100) / 100); });
        xs = uniqSort(xs); ys = uniqSort(ys);
        for (var yi = 0; yi < ys.length; yi++) for (var xi = 0; xi < xs.length; xi++) { var x = xs[xi], y = ys[yi]; if (x + item.wIn <= usable + EPS && !overlapsAny(x, y, item.wIn, item.hIn, item.id)) { item.xIn = x; item.yIn = y; return; } }
        var maxB = 0; state.items.forEach(function (it) { if (it.id !== item.id) maxB = Math.max(maxB, it.yIn + it.hIn); });
        item.xIn = 0; item.yIn = maxB;
    }

    function sizeGroups() {
        var map = Object.create(null);
        state.items.forEach(function (it) { var key = it.wIn.toFixed(2) + 'x' + it.hIn.toFixed(2); if (!map[key]) map[key] = { key: key, wIn: it.wIn, hIn: it.hIn, count: 0 }; map[key].count += 1; });
        return Object.keys(map).map(function (k) { return map[k]; }).sort(function (a, b) { return b.count - a.count || b.wIn * b.hIn - a.wIn * a.hIn; });
    }
    function feedUsedIn() { var m = 0; state.items.forEach(function (it) { m = Math.max(m, it.yIn + it.hIn); }); return m; }
    /**
     * The roll length the calculator's nesting math assumes for this layout —
     * the length the shopper is being charged for.
     *
     * Only meaningful for a single footprint, which is the case the calculator
     * prices as one line. A mixed layout is checked out a size group at a time,
     * so there is no single baseline to compare against; 0 means "no comparison".
     */
    function nestingFeedIn() {
        var groups = sizeGroups();
        if (groups.length !== 1 || !state.items.length) return 0;
        var g = groups[0], usable = currentRoll().usableInches;
        if (g.wIn <= 0 || g.hIn <= 0) return 0;
        var across = Math.floor((usable + EPS) / g.wIn);
        if (across < 1) return 0;
        return Math.ceil(state.items.length / across) * g.hIn;
    }
    /** Roll length the layout wastes against ideal nesting, in inches. */
    function feedWasteIn() {
        var nest = nestingFeedIn();
        if (nest <= 0) return 0;
        return Math.max(0, feedUsedIn() - nest);
    }
    function packStats() { return pack(state.items.map(function (it) { return { id: it.id, wIn: it.wIn, hIn: it.hIn, rotated: false }; }), currentRoll().usableInches, { autoArrange: true, autoRotate: false }); }

    /**
     * Add a placeholder: space reserved on the roll for artwork the shopper does
     * not have to hand. It is drawn rather than loaded — there is no file behind
     * it — so it never uploads, and it carries its own id so duplicates of the
     * same placeholder group together on the order exactly like a real image.
     *
     * Starts at 3:2 with the aspect left unlocked, because the whole point is to
     * type in the print size you want.
     */
    function addPlaceholder() {
        var roll = currentRoll();
        var wIn = Math.min(12, Math.max(MIN_SIDE_IN, roll.usableInches));
        var hIn = Math.max(MIN_SIDE_IN, wIn * 2 / 3);
        var item = {
            id: nextId++, name: t('placeholderName'), src: '', file: null,
            placeholder: true, phId: 'ph_' + randomHex(16),
            stashId: '', stashState: 'idle',
            pxW: 3000, pxH: 2000,
            wIn: wIn, hIn: hIn, xIn: 0, yIn: 0,
            aspectLocked: false, rotation: 0
        };
        state.items.push(item); autoPlace(item);
        state.selectedIds = [item.id]; state.appliedGroupKey = null;
        afterItemsChanged(true);
        return item.id;
    }
    function randomHex(bytes) {
        var out = '';
        if (window.crypto && window.crypto.getRandomValues) {
            var b = new Uint8Array(bytes); window.crypto.getRandomValues(b);
            for (var i = 0; i < b.length; i++) out += ('0' + b[i].toString(16)).slice(-2);
            return out;
        }
        while (out.length < bytes * 2) out += Math.floor(Math.random() * 16).toString(16);
        return out.slice(0, bytes * 2);
    }
    function addItem(name, src, pxW, pxH, file) {
        var size = defaultSizeFor(pxW, pxH); retainSrc(src);
        var item = { id: nextId++, name: name, src: src, file: file || null, stashId: '', stashState: 'idle', pxW: pxW, pxH: pxH, wIn: size.wIn, hIn: size.hIn, xIn: 0, yIn: 0, aspectLocked: true, rotation: 0 };
        state.items.push(item); autoPlace(item); state.selectedIds = [item.id]; state.appliedGroupKey = null; afterItemsChanged(true); return item.id;
    }
    /**
     * Seat a duplicate flush to the right of its original, on the same row.
     * Returns false when it would run past the printable width or collide, so
     * the caller can fall back to the normal top-left scan.
     */
    function placeRightOf(copy, source) {
        var usable = currentRoll().usableInches;
        var x = source.xIn + source.wIn, y = source.yIn;
        if (x + copy.wIn > usable + EPS) return false;
        if (overlapsAny(x, y, copy.wIn, copy.hIn, copy.id)) return false;
        copy.xIn = x; copy.yIn = y; return true;
    }
    function cloneItem(it) {
        retainSrc(it.src);
        var copy = { id: nextId++, name: it.name, src: it.src, file: it.file || null, stashId: it.stashId || '', stashState: it.stashId ? 'done' : 'idle', pxW: it.pxW, pxH: it.pxH, wIn: it.wIn, hIn: it.hIn, xIn: it.xIn, yIn: it.yIn, aspectLocked: it.placeholder ? it.aspectLocked : true, rotation: it.rotation };
        if (it.placeholder) { copy.placeholder = true; copy.phId = it.phId; }
        state.items.push(copy);
        if (!placeRightOf(copy, it)) autoPlace(copy);
        return copy;
    }
    function duplicateItem(id) { var it = findItem(id); if (!it) return; var copy = cloneItem(it); state.selectedIds = [copy.id]; state.appliedGroupKey = null; afterItemsChanged(true); return copy.id; }
    function duplicateSelected() {
        var items = selectedItems(); if (!items.length) return;
        var ids = items.map(function (it) { return cloneItem(it).id; });
        state.selectedIds = ids; state.appliedGroupKey = null; afterItemsChanged(true);
    }
    function removeItem(id) { var it = findItem(id); if (!it) return; state.items.splice(state.items.indexOf(it), 1); releaseSrc(it.src); state.selectedIds = state.selectedIds.filter(function (x) { return x !== id; }); state.appliedGroupKey = null; afterItemsChanged(true); }
    function removeSelected() { var ids = state.selectedIds.slice(); if (!ids.length) return; ids.forEach(function (id) { var it = findItem(id); if (it) { state.items.splice(state.items.indexOf(it), 1); releaseSrc(it.src); } }); state.selectedIds = []; state.appliedGroupKey = null; afterItemsChanged(true); }
    function clearAll() { state.items.slice().forEach(function (it) { releaseSrc(it.src); }); state.items = []; state.selectedIds = []; state.appliedGroupKey = null; afterItemsChanged(true); }
    function replaceItemImage(id, name, src, pxW, pxH, file) {
        var it = findItem(id); if (!it) return; releaseSrc(it.src); retainSrc(src);
        it.name = name; it.src = src; it.file = file || null; it.stashId = ''; it.stashState = 'idle'; it.pxW = pxW; it.pxH = pxH;
        var ratio = footprintAspect(it); it.hIn = it.wIn / ratio; var usable = currentRoll().usableInches; if (it.wIn > usable) { it.wIn = usable; it.hIn = it.wIn / ratio; }
        if (overlapsAny(it.xIn, it.yIn, it.wIn, it.hIn, it.id)) autoPlace(it);
        afterItemsChanged(false);
    }
    /**
     * Swap a print's width and height.
     *
     * For a placeholder the two numbers simply trade places. For a photograph
     * they cannot: the frame fills its box, so a landscape image forced into a
     * portrait footprint would print stretched. Turning it a quarter gives the
     * swapped footprint the shopper asked for with the artwork still true.
     */
    function swapSelectedDims() {
        var items = selectedItems(); if (!items.length) return;
        var usable = currentRoll().usableInches, rotated = false;
        items.forEach(function (it) {
            if (it.placeholder) {
                setItemSize(it, it.hIn, it.wIn);
            } else {
                it.rotation = (it.rotation + 90) % 360;
                var tmp = it.wIn; it.wIn = it.hIn; it.hIn = tmp;
                if (it.wIn > usable) { var f = usable / it.wIn; it.wIn = usable; it.hIn *= f; }
                setItemSize(it, it.wIn, it.hIn);
                rotated = true;
            }
        });
        state.appliedGroupKey = null;
        afterItemsChanged(true);
        return rotated;
    }
    function rotateSelected() {
        var items = selectedItems(); if (!items.length) return;
        var usable = currentRoll().usableInches;
        items.forEach(function (it) {
            it.rotation = (it.rotation + 90) % 360;
            var tmp = it.wIn; it.wIn = it.hIn; it.hIn = tmp;
            if (it.wIn > usable) { var f = usable / it.wIn; it.wIn = usable; it.hIn *= f; }
            it.xIn = clamp(it.xIn, 0, Math.max(0, usable - it.wIn));
        });
        items.forEach(function (it) { if (overlapsAny(it.xIn, it.yIn, it.wIn, it.hIn, it.id)) autoPlace(it); });
        state.appliedGroupKey = null; afterItemsChanged(true);
    }
    function scaleSelected(factor) {
        var items = selectedItems(); if (!items.length) return;
        var others = state.items.filter(function (it) { return !isSelected(it.id); });
        var b = groupBounds(items);
        var res = computeGroupScaleAbout(items, others, factor, b.cx, b.cy, currentRoll().usableInches);
        items.forEach(function (it) { var p = res.pos[it.id]; if (p) { it.xIn = p.x; it.yIn = p.y; it.wIn = p.w; it.hIn = p.h; } });
        state.appliedGroupKey = null; afterItemsChanged(true);
    }
    function rotateItem(id) {
        var it = findItem(id); if (!it) return;
        it.rotation = (it.rotation + 90) % 360;
        var tmp = it.wIn; it.wIn = it.hIn; it.hIn = tmp;
        var usable = currentRoll().usableInches;
        if (it.wIn > usable) { var f = usable / it.wIn; it.wIn = usable; it.hIn *= f; }
        it.xIn = clamp(it.xIn, 0, Math.max(0, usable - it.wIn));
        if (overlapsAny(it.xIn, it.yIn, it.wIn, it.hIn, it.id)) autoPlace(it);
        state.appliedGroupKey = null; afterItemsChanged(true);
    }
    function arrangeAll() { var res = computeArrange(state.items, currentRoll().usableInches); state.items.forEach(function (it) { var p = res.pos[it.id]; if (p) { it.xIn = p.x; it.yIn = p.y; } }); afterItemsChanged(false); }
    function setItemSize(it, wIn, hIn) {
        var usable = currentRoll().usableInches;
        it.wIn = clamp(wIn, MIN_SIDE_IN, usable); it.hIn = Math.max(MIN_SIDE_IN, hIn);
        it.xIn = clamp(it.xIn, 0, Math.max(0, usable - it.wIn));
        if (overlapsAny(it.xIn, it.yIn, it.wIn, it.hIn, it.id)) autoPlace(it);
        state.appliedGroupKey = null;
    }
    function syncCalculator() {
        var n = state.items.length;
        if (n === 0) { bridge.syncQuantity(1); return; }
        if (state.appliedGroupKey) { var g = sizeGroups().filter(function (x) { return x.key === state.appliedGroupKey; })[0]; if (g) { bridge.applyDims(g.wIn, g.hIn, state.units); bridge.syncQuantity(g.count); return; } state.appliedGroupKey = null; }
        bridge.syncQuantity(n);
        var groups = sizeGroups(); if (groups.length === 1) bridge.applyDims(groups[0].wIn, groups[0].hIn, state.units);
    }
    /**
     * The roll length this layout should be priced at, in cm — or 0 for "price
     * it by nesting".
     *
     * These conditions are deliberately identical to
     * fac_layout_feed_cm_for_state() in includes/layout-images.php. A layout
     * belongs to the order but the calculator prices one size at a time, so it
     * may only price a line that unambiguously *is* the whole layout. If the two
     * sides ever disagree the add-to-cart endpoint rejects the order outright,
     * so they are kept in lockstep on purpose.
     */
    function layoutFeedCmForPricing() {
        if (!state.items.length) return 0;
        var groups = sizeGroups();
        if (groups.length !== 1) return 0;

        var qty = bridge.readQty();
        if (qty === null || qty !== state.items.length) return 0;

        var wEl = document.getElementById('dimension-width-input');
        var hEl = document.getElementById('dimension-height-input');
        if (!wEl || !hEl) return 0;
        var wIn = fromDisplay(parseFloat(wEl.value), state.units);
        var hIn = fromDisplay(parseFloat(hEl.value), state.units);
        if (!isFinite(wIn) || !isFinite(hIn) || wIn <= 0 || hIn <= 0) return 0;

        var g = groups[0];
        var match = (Math.abs(g.wIn - wIn) < 0.02 && Math.abs(g.hIn - hIn) < 0.02)
                 || (Math.abs(g.wIn - hIn) < 0.02 && Math.abs(g.hIn - wIn) < 0.02);
        if (!match) return 0;

        return feedUsedIn() * CM_PER_IN;
    }
    /**
     * Hand that length to the calculator and make it re-price.
     *
     * Dragging a print changes no calculator input, so nothing would prompt a
     * recalculation on its own. Re-writing the width field with the value it
     * already holds does it: the calculator builds a fresh state object on every
     * change, so the render runs again and picks up the new length without
     * altering a single thing the shopper chose.
     */
    function pushLayoutFeed() {
        var cm = layoutFeedCmForPricing();
        var prev = +window.__FAC_LAYOUT_FEED_CM || 0;
        window.__FAC_LAYOUT_FEED_CM = cm;
        if (Math.abs(cm - prev) < 0.005) return;
        bridge.forceRecalc();
    }
    function afterItemsChanged(sync) {
        if (sync) syncCalculator();
        updateChrome(); layoutCanvas(); artwork.schedule();
        // Deferred: quantity and dimensions are written through the calculator's
        // own inputs just above, and their values settle on the next tick.
        window.setTimeout(pushLayoutFeed, 0);
    }

    var decodeErrors = [];
    function ingestFiles(fileList, replaceId) {
        Array.prototype.slice.call(fileList || []).forEach(function (file, idx) {
            var src = URL.createObjectURL(file), img = new Image();
            img.onload = function () { var pxW = img.naturalWidth, pxH = img.naturalHeight; if (!(pxW > 0 && pxH > 0)) { img.onerror(); return; } if (replaceId !== undefined && idx === 0) replaceItemImage(replaceId, file.name, src, pxW, pxH, file); else addItem(file.name, src, pxW, pxH, file); };
            img.onerror = function () { URL.revokeObjectURL(src); decodeErrors.push(t('decodeError', { name: file.name })); updateChrome(); };
            img.src = src;
        });
    }

    function buildSkeleton(container) {
        dom.container = container; container.classList.add('faclp');
        dom.fileInput = el('input', { type: 'file', accept: 'image/*', multiple: 'multiple', style: 'display:none', 'aria-hidden': 'true' });
        dom.replaceInput = el('input', { type: 'file', accept: 'image/*', style: 'display:none', 'aria-hidden': 'true' });
        dom.fileInput.addEventListener('change', function () { ingestFiles(dom.fileInput.files); dom.fileInput.value = ''; });
        dom.replaceInput.addEventListener('change', function () { if (dom.replaceInput._targetId !== undefined) ingestFiles(dom.replaceInput.files, dom.replaceInput._targetId); dom.replaceInput.value = ''; });

        dom.card = el('div', { className: 'faclp__card' });
        dom.pricebar = el('div', { className: 'faclp__pricebar' }, [
            el('span', { className: 'faclp__pricebar-label', text: t('priceLabel') }),
            el('span', { className: 'faclp__pricebar-value' }),
            el('span', { className: 'faclp__pricebar-per' })
        ]);
        dom.head = el('div', { className: 'faclp__head' }, [
            el('div', { className: 'faclp__head-titles' }, [
                el('h2', { className: 'faclp__title', text: t('title') }),
                el('span', { className: 'faclp__tag', text: t('tag') })
            ]),
            dom.pricebar
        ]);
        dom.rollline = el('p', { className: 'faclp__rollline' });
        dom.lede = el('p', { className: 'faclp__lede', text: t('lede', { ppi: TARGET_PPI }) });
        dom.toolbar = el('div', { className: 'faclp__toolbar' });
        dom.selbar = el('div', { className: 'faclp__selbar' });
        dom.statsWrap = el('div'); dom.noticesWrap = el('div');
        dom.viewport = el('div', { className: 'faclp__viewport' });
        dom.viewport.addEventListener('pointerdown', function (e) { onViewportPointerDown(e); });
        dom.legend = renderLegend();
        dom.hint = el('p', { className: 'faclp__hint', text: t('planningOnly', { ppi: TARGET_PPI }) });
        [dom.head, dom.rollline, dom.lede, dom.toolbar, dom.statsWrap, dom.noticesWrap, dom.selbar, dom.viewport, dom.legend, dom.hint].forEach(function (n) { dom.card.appendChild(n); });
        container.appendChild(dom.card); container.appendChild(dom.fileInput); container.appendChild(dom.replaceInput);

        ['dragenter', 'dragover'].forEach(function (n) { dom.card.addEventListener(n, function (e) { e.preventDefault(); container.classList.add('faclp--dragging'); }); });
        ['dragleave', 'drop'].forEach(function (n) { dom.card.addEventListener(n, function (e) { e.preventDefault(); if (n === 'drop' && e.dataTransfer) ingestFiles(e.dataTransfer.files); container.classList.remove('faclp--dragging'); }); });
        document.addEventListener('keydown', onKeydown, false);
        window.addEventListener('resize', scheduleLayout);
    }

    function onKeydown(e) {
        if (!state.selectedIds.length) return;
        var a = document.activeElement; if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT')) return;
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeSelected(); }
        else if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) { e.preventDefault(); duplicateSelected(); }
        else if (e.key === 'r' || e.key === 'R') { e.preventDefault(); rotateSelected(); }
        else if (e.key === 'Escape') { e.preventDefault(); clearSelection(); }
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            var step = e.shiftKey ? 1 : NUDGE_IN;
            var dx = (e.key === 'ArrowRight' ? step : e.key === 'ArrowLeft' ? -step : 0), dy = (e.key === 'ArrowDown' ? step : e.key === 'ArrowUp' ? -step : 0);
            var sel = selectedItems(), others = state.items.filter(function (x) { return !isSelected(x.id); });
            var placed = computeGroupTranslate(sel, others, dx, dy, currentRoll().usableInches);
            if (placed) { sel.forEach(function (it) { var p = placed[it.id]; if (p) { it.xIn = p.x; it.yIn = p.y; } }); state.appliedGroupKey = null; afterItemsChanged(false); }
        }
    }
    function tryPlaceFor(it, x, y) { var usable = currentRoll().usableInches, cx = clamp(x, 0, Math.max(0, usable - it.wIn)), cy = Math.max(0, y); return overlapsAny(cx, cy, it.wIn, it.hIn, it.id) ? null : { x: cx, y: cy }; }

    var layoutQueued = false;
    function scheduleLayout() { if (layoutQueued || state.dragging) return; layoutQueued = true; window.requestAnimationFrame(function () { layoutQueued = false; layoutCanvas(); }); }

    function updateChrome() {
        var roll = currentRoll(), units = state.units;
        dom.rollline.innerHTML = '<strong>' + escapeHtml(t('rollLine', { roll: roll.key + '"', printable: fmtLen(roll.usableInches, units, 1) })) + '</strong>';
        dom.toolbar.textContent = '';
        var addBtn = el('button', { type: 'button', className: 'faclp__btn faclp__btn--primary' }, [svgIcon(ICONS.upload, 'faclp__btn-ico')]);
        addBtn.appendChild(document.createTextNode(t('addImages'))); addBtn.addEventListener('click', function () { dom.fileInput.click(); });
        dom.toolbar.appendChild(addBtn);
        var phBtn = el('button', { type: 'button', className: 'faclp__btn', title: t('placeholderBody') }, [svgIcon(ICONS.placeholder, 'faclp__btn-ico')]);
        phBtn.appendChild(document.createTextNode(t('addPlaceholder')));
        phBtn.addEventListener('click', function () { addPlaceholder(); });
        dom.toolbar.appendChild(phBtn);
        dom.toolbar.appendChild(buildUnitToggle());
        if (state.items.length) {
            var arrBtn = el('button', { type: 'button', className: 'faclp__btn' }, [svgIcon(ICONS.arrange, 'faclp__btn-ico')]); arrBtn.appendChild(document.createTextNode(t('arrange'))); arrBtn.addEventListener('click', arrangeAll); dom.toolbar.appendChild(arrBtn);
            var clrBtn = el('button', { type: 'button', className: 'faclp__btn faclp__btn--danger', text: t('clearAll') }); clrBtn.addEventListener('click', clearAll); dom.toolbar.appendChild(clrBtn);
        }
        dom.statsWrap.textContent = '';
        if (state.items.length) {
            var qtyNow = bridge.readQty(), ps = packStats();
            dom.statsWrap.appendChild(el('div', { className: 'faclp__stats' }, [
                stat(t('prints'), String(state.items.length), qtyNow === state.items.length ? t('syncedQty') : ''),
                stat(t('feedUsed'), fmtLen(feedUsedIn(), units, 1),
                    feedUsedIn() < MIN_FEED_IN - EPS
                        ? t('minFeedStatSub', { min: fmtLen(MIN_FEED_IN, units, 2) })
                        : (feedWasteIn() > 0.25
                            ? t('feedChargedSub', { nest: fmtLen(nestingFeedIn(), units, 1) })
                            : fmtLen(feedUsedIn(), units === 'inches' ? 'centimeters' : 'inches', 1)),
                    feedWasteIn() > 0.25),
                stat(t('utilization'), fmtNum(ps.utilization * 100, 0) + '%', '', ps.utilization >= 0.8),
                stat(t('widestGap'), ps.maxLeftover > 0.05 ? fmtLen(ps.maxLeftover, units, 1) : t('none'), '')
            ]));
        }
        renderNotices(roll, units);
        updateSelbar();
    }
    function renderNotices(roll, units) {
        if (!dom.noticesWrap) return;
        dom.noticesWrap.textContent = '';
        buildNotices(roll, units).forEach(function (n) { dom.noticesWrap.appendChild(n); });
    }
    /** Refresh just the notice strip — used while uploading, so a long upload
     *  updates its percentage without rebuilding the toolbar or the canvas. */
    function updateNoticesOnly() {
        if (!dom.noticesWrap) return;
        renderNotices(currentRoll(), state.units);
    }
    // The persistent edit bar above the canvas. It reflects the current
    // selection so image controls are always reachable (never trapped beneath
    // the sticky ruler) and works the same for one print or many.
    function updateSelbar() {
        if (!dom.selbar) return;
        // Don't rebuild while the shopper is typing a size into it.
        if (document.activeElement && dom.selbar.contains(document.activeElement)) return;
        var units = state.units, sel = selectedItems();
        dom.selbar.textContent = '';
        if (!state.items.length) { dom.selbar.classList.remove('faclp__selbar--active'); return; }
        if (!sel.length) {
            dom.selbar.classList.remove('faclp__selbar--active');
            dom.selbar.appendChild(el('span', { className: 'faclp__selbar-hint', text: t('selPrompt') }));
            return;
        }
        dom.selbar.classList.add('faclp__selbar--active');
        if (sel.length === 1) buildSingleSelbar(sel[0], units); else buildGroupSelbar(sel, units);
    }
    /**
     * Inches / centimetres, set from inside the planner.
     *
     * It drives the calculator's own toggle so the whole form agrees; the
     * planner then reflects the change like any other calculator update.
     */
    function buildUnitToggle() {
        var wrap = el('div', { className: 'faclp__units', role: 'group', 'aria-label': t('units') });
        [['inches', t('unitIn')], ['centimeters', t('unitCm')]].forEach(function (pair) {
            var on = state.units === pair[0];
            var b = el('button', {
                type: 'button',
                className: 'faclp__unitbtn' + (on ? ' faclp__unitbtn--on' : ''),
                text: pair[1],
                'aria-pressed': on ? 'true' : 'false'
            });
            b.addEventListener('click', function () {
                if (state.units === pair[0]) return;
                if (bridge.setUnits(pair[0])) {
                    // Reflect it at once; the poll would otherwise lag a beat.
                    state.units = pair[0];
                    afterItemsChanged(false);
                }
            });
            wrap.appendChild(b);
        });
        return wrap;
    }
    function selIconBtn(icon, title, onClick, danger) {
        var b = el('button', { type: 'button', className: 'faclp__iconbtn' + (danger ? ' faclp__iconbtn--danger' : ''), title: title }, [svgIcon(icon)]);
        b.addEventListener('click', onClick); return b;
    }
    function buildSingleSelbar(it, units) {
        var dec = units === 'centimeters' ? 1 : 2;
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-label', text: t('selCountOne') }));
        /*
         * Each field carries a real <label for=…>, so the association is
         * programmatic rather than just visual. The short form is what shows;
         * the full word sits beside it for screen readers, which would
         * otherwise announce a bare letter.
         */
        var wId = 'faclp-w-' + it.id, hId = 'faclp-h-' + it.id;
        function fieldLabel(forId, short, full) {
            return el('label', { className: 'faclp__selbar-fieldlabel', 'for': forId }, [
                el('span', { 'aria-hidden': 'true', text: short }),
                el('span', { className: 'faclp__sr-only', text: full })
            ]);
        }
        // inputmode text (not decimal) so the arithmetic keys are reachable on mobile.
        var wInp = el('input', { id: wId, className: 'faclp__selbar-input', type: 'text', inputmode: 'text', autocomplete: 'off', spellcheck: 'false', value: fmtNum(toDisplay(it.wIn, units), dec) });
        var hInp = el('input', { id: hId, className: 'faclp__selbar-input', type: 'text', inputmode: 'text', autocomplete: 'off', spellcheck: 'false', value: fmtNum(toDisplay(it.hIn, units), dec) });
        /**
         * Apply what is in the fields to the print.
         *
         * `settle` means the value is final — pressing Enter or leaving the
         * field — and only then is the field rewritten, so an expression is
         * replaced by its answer. While the shopper is still typing a plain
         * number the field is left exactly as they typed it; rewriting it
         * mid-keystroke would fight them.
         */
        function commit(which, settle) {
            var w = fromDisplay(parseLen(wInp.value), units), h = fromDisplay(parseLen(hInp.value), units), ratio = aspectRatioFor(it);
            // Locked: the side you did not type follows. Free: both stand as typed.
            if (ratio > 0) {
                if (which === 'w' && isFinite(w) && w > 0) h = w / ratio;
                else if (which === 'h' && isFinite(h) && h > 0) w = h * ratio;
            }
            if (!(isFinite(w) && w > 0)) w = it.wIn; if (!(isFinite(h) && h > 0)) h = it.hIn;
            setItemSize(it, w, h);
            // Read back from the print, not the inputs: setItemSize may have
            // clamped the size to fit the roll.
            if (settle) {
                wInp.value = fmtNum(toDisplay(it.wIn, units), dec);
                hInp.value = fmtNum(toDisplay(it.hIn, units), dec);
            } else if (ratio > 0) {
                // Locked and typing: keep the partner field honest, but never
                // touch the one under the cursor.
                var other = (which === 'w') ? hInp : wInp;
                other.value = fmtNum(toDisplay(which === 'w' ? it.hIn : it.wIn, units), dec);
            }
            afterItemsChanged(true);
        }
        /*
         * A half-written sum is not a size. "8+" or "8+2" only means something
         * once it is finished, so live updating pauses as soon as an operator
         * appears and resumes on Enter or when the field is left.
         */
        function composingExpr(v) { return /[+\-*/()×÷]/.test(String(v)); }
        function markPending(inp, on) {
            inp.classList.toggle('faclp__selbar-input--pending', on);
            if (on) { inp.setAttribute('title', t('exprHint')); } else { inp.removeAttribute('title'); }
        }
        [[wInp, 'w'], [hInp, 'h']].forEach(function (pair) {
            var inp = pair[0], which = pair[1];
            inp.addEventListener('input', function () {
                var waiting = composingExpr(inp.value);
                markPending(inp, waiting);
                if (waiting) return;          // hold until the sum is confirmed
                commit(which, false);         // plain number: straight through, live
            });
            inp.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                markPending(inp, false);
                commit(which, true);
                inp.select();
            });
            inp.addEventListener('change', function () { markPending(inp, false); commit(which, true); });
            inp.addEventListener('focus', function () { inp.select(); });
        });
        // The swap control sits between the two fields, exactly where it lived in
        // the original dimensions row, and carries the same icon and turn.
        var swapBtn = selIconBtn(ICONS.swapDims, t('swapDims'), function () { swapSelectedDims(); });
        swapBtn.classList.add('faclp__swapbtn');
        var dimParts = [
            el('span', { className: 'faclp__selbar-field' }, [fieldLabel(wId, t('width'), t('widthFull')), wInp]),
            swapBtn,
            el('span', { className: 'faclp__selbar-field' }, [fieldLabel(hId, t('height'), t('heightFull')), hInp]),
            el('span', { className: 'faclp__selbar-unit', text: unitSuffix(units) })
        ];
        if (it.placeholder) {
            // Lock sits to the right of the pair it governs, as in Figma.
            var lockBtn = selIconBtn(it.aspectLocked ? ICONS.lockOn : ICONS.lockOff,
                it.aspectLocked ? t('aspectUnlock') : t('aspectLock'),
                function () { toggleAspectLock(it.id); });
            lockBtn.classList.add('faclp__selbar-lock');
            if (it.aspectLocked) lockBtn.classList.add('faclp__selbar-lock--on');
            lockBtn.setAttribute('aria-pressed', it.aspectLocked ? 'true' : 'false');
            dimParts.push(lockBtn);
        }
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-dims' }, dimParts));
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-sep' }));
        dom.selbar.appendChild(selIconBtn(ICONS.scale, t('scaleDown'), function () { scaleSelected(1 / 1.1); }));
        dom.selbar.appendChild(selIconBtn(ICONS.scale, t('scaleUp'), function () { scaleSelected(1.1); }));
        dom.selbar.appendChild(selIconBtn(ICONS.rotate, t('rotate'), function () { rotateSelected(); }));
        dom.selbar.appendChild(selIconBtn(ICONS.copy, t('duplicate'), function () { duplicateSelected(); }));
        if (!it.placeholder) dom.selbar.appendChild(selIconBtn(ICONS.swap, t('replace'), function () { triggerReplace(it.id); }));
        dom.selbar.appendChild(selIconBtn(ICONS.trash, t('remove'), function () { removeSelected(); }, true));
        if (isLowRes(it)) { var mx = maxSizeAt400(it); dom.selbar.appendChild(el('span', { className: 'faclp__selbar-note', text: t('maxAt400', { dims: fmtDims(mx.wIn, mx.hIn, units), ppi: TARGET_PPI }) })); }
    }
    function buildGroupSelbar(sel, units) {
        dom.selbar.appendChild(svgIcon(ICONS.layers, 'faclp__selbar-ico'));
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-label', text: t('selCountMany', { n: sel.length }) }));
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-sep' }));
        dom.selbar.appendChild(selIconBtn(ICONS.scale, t('scaleDown'), function () { scaleSelected(1 / 1.1); }));
        dom.selbar.appendChild(selIconBtn(ICONS.scale, t('scaleUp'), function () { scaleSelected(1.1); }));
        dom.selbar.appendChild(selIconBtn(ICONS.rotate, t('rotate'), function () { rotateSelected(); }));
        dom.selbar.appendChild(selIconBtn(ICONS.copy, t('duplicate'), function () { duplicateSelected(); }));
        dom.selbar.appendChild(selIconBtn(ICONS.trash, t('remove'), function () { removeSelected(); }, true));
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-sep' }));
        var deselect = el('button', { type: 'button', className: 'faclp__selbar-link', text: t('deselect') }); deselect.addEventListener('click', function () { clearSelection(); });
        dom.selbar.appendChild(deselect);
        dom.selbar.appendChild(el('span', { className: 'faclp__selbar-hint faclp__selbar-hint--inline', text: t('groupHint') }));
    }
    // Marquee: pointer-down on empty canvas drags a selection box; a plain click
    // (no drag) clears the selection. Shift keeps the current selection.
    function onViewportPointerDown(e) {
        if (state.dragging) return;
        if (e.button !== undefined && e.button !== 0) return;
        if (e.target && e.target.closest && (e.target.closest('.faclp__print') || e.target.closest('.faclp__groupbox'))) return;
        if (!dom.printable || !dom.pxPerIn) { clearSelection(); return; }
        var rect = printableRect(), pxPerIn = dom.pxPerIn, additive = e.shiftKey;
        var startX = (e.clientX - rect.left) / pxPerIn, startY = (e.clientY - rect.top) / pxPerIn, moved = false;
        var box = null, baseline = additive ? state.selectedIds.slice() : [];
        state.marquee = true; state.dragging = true;
        var capNode = dom.viewport; try { capNode.setPointerCapture(e.pointerId); } catch (err) {}
        function rectFromPointer(ev) {
            var x = (ev.clientX - rect.left) / pxPerIn, y = (ev.clientY - rect.top) / pxPerIn;
            return { x0: Math.min(startX, x), y0: Math.min(startY, y), x1: Math.max(startX, x), y1: Math.max(startY, y) };
        }
        function move(ev) {
            var r = rectFromPointer(ev);
            if (!moved && (Math.abs(r.x1 - r.x0) > 0.12 || Math.abs(r.y1 - r.y0) > 0.12)) {
                moved = true; box = el('div', { className: 'faclp__marquee' }); dom.printable.appendChild(box);
            }
            if (!box) return;
            var p = pxPerIn;
            box.style.left = (r.x0 * p) + 'px'; box.style.top = (r.y0 * p) + 'px'; box.style.width = ((r.x1 - r.x0) * p) + 'px'; box.style.height = ((r.y1 - r.y0) * p) + 'px';
            var hits = state.items.filter(function (it) { return rectsOverlap(r.x0, r.y0, r.x1 - r.x0, r.y1 - r.y0, it.xIn, it.yIn, it.wIn, it.hIn); }).map(function (it) { return it.id; });
            var merged = baseline.slice(); hits.forEach(function (id) { if (merged.indexOf(id) === -1) merged.push(id); });
            for (var id in nodeById) { var n = nodeById[id]; if (n) n.classList.toggle('faclp__print--marquee', merged.indexOf(parseInt(id, 10)) !== -1); }
        }
        function up(ev) {
            capNode.removeEventListener('pointermove', move); capNode.removeEventListener('pointerup', up); capNode.removeEventListener('pointercancel', up);
            try { capNode.releasePointerCapture(e.pointerId); } catch (err) {}
            for (var id in nodeById) { var n = nodeById[id]; if (n) n.classList.remove('faclp__print--marquee'); }
            if (box && box.parentNode) box.parentNode.removeChild(box);
            state.marquee = false; state.dragging = false;
            if (!moved) { clearSelection(); return; }
            var r = rectFromPointer(ev);
            var hits = state.items.filter(function (it) { return rectsOverlap(r.x0, r.y0, r.x1 - r.x0, r.y1 - r.y0, it.xIn, it.yIn, it.wIn, it.hIn); }).map(function (it) { return it.id; });
            var merged = baseline.slice(); hits.forEach(function (id) { if (merged.indexOf(id) === -1) merged.push(id); });
            setSelection(merged);
        }
        capNode.addEventListener('pointermove', move); capNode.addEventListener('pointerup', up); capNode.addEventListener('pointercancel', up);
    }
    function stat(label, value, sub, accent) { return el('div', { className: 'faclp__stat' }, [el('span', { className: 'faclp__stat-label', text: label }), el('div', { className: 'faclp__stat-value' + (accent ? ' faclp__stat-value--accent' : ''), text: value }), sub ? el('div', { className: 'faclp__stat-sub', text: sub }) : null]); }
    function renderLegend() {
        function chip(cls, label) { return el('span', { className: 'faclp__legend-item' }, [el('span', { className: 'faclp__legend-swatch ' + cls }), el('span', { text: label })]); }
        return el('div', { className: 'faclp__legend' }, [chip('faclp__legend-swatch--paper', t('legendPrintable')), chip('faclp__legend-swatch--edge', t('legendEdge')), chip('faclp__legend-swatch--off', t('legendOff'))]);
    }
    function notice(kind, strong, body) {
        var icon = kind === 'error' ? ICONS.error : kind === 'warn' ? ICONS.warn : ICONS.info;
        var cls = 'faclp__notice' + (kind === 'error' ? ' faclp__notice--error' : kind === 'warn' ? ' faclp__notice--warn' : '');
        return el('div', { className: cls, role: 'note' }, [svgIcon(icon, 'faclp__notice-ico'), el('div', { className: 'faclp__notice-body', html: (strong ? '<strong>' + escapeHtml(strong) + '</strong> ' : '') + escapeHtml(body) })]);
    }
    function buildNotices(roll, units) {
        var out = [];
        while (decodeErrors.length) out.push(notice('error', '', decodeErrors.shift()));
        var lowCount = state.items.filter(isLowRes).length;
        if (lowCount) out.push(notice('warn', t('lowResSummary', { n: lowCount, ppi: TARGET_PPI }), t('lowResSummaryBody', { ppi: TARGET_PPI })));
        /*
         * Spreading prints down the roll consumes paper that nesting would not.
         * Show it while the shopper can still act on it — and say it costs money
         * only once the price actually follows the layout.
         */
        var waste = feedWasteIn();
        if (waste > 0.25) {
            var priced = !!window.__FAC_LAYOUT_PRICING;
            out.push(notice(priced ? 'warn' : 'info',
                t(priced ? 'wasteTitlePriced' : 'wasteTitleFree', { extra: fmtLen(waste, units, 1) }),
                t(priced ? 'wasteBodyPriced' : 'wasteBodyFree', {
                    arrange: t('wasteArrangeName'),
                    nest: fmtLen(nestingFeedIn(), units, 1),
                    used: fmtLen(feedUsedIn(), units, 1)
                })));
        }
        // A short run is not refused — it is simply charged at the minimum feed.
        // Say so here, where the shopper can still do something about it.
        var feedNow = feedUsedIn();
        if (state.items.length && feedNow > EPS && feedNow < MIN_FEED_IN - EPS) {
            out.push(notice('info',
                t('minFeedTitle', { min: fmtLen(MIN_FEED_IN, units, 2) }),
                t('minFeedBody', {
                    min: fmtLen(MIN_FEED_IN, units, 2),
                    minmm: Math.round(MIN_FEED_CM * 10),
                    free: fmtLen(MIN_FEED_IN - feedNow, units, 2)
                })));
        }
        if (artwork.uploading > 0) {
            if (artwork.blockedCheckout) out.push(notice('warn', t('checkoutHeld'), t('checkoutHeldBody')));
            out.push(notice('info', '', artwork.progressLabel
                ? t('uploadingPct', { name: artwork.progressLabel, pct: Math.round((artwork.progress || 0) * 100), n: artwork.uploading })
                : t('uploadingNow', { n: artwork.uploading })));
        }
        var phs = artwork.placeholderCount();
        if (phs) out.push(notice('info', t('placeholderNote', { n: phs }), t('placeholderBody')));
        var failed = artwork.failedCount();
        if (failed) {
            // Include the reason: it is the difference between the studio
            // guessing and the shopper being able to say what happened.
            var codes = {};
            state.items.forEach(function (it) { if (it.stashState === 'failed' && it.uploadError) codes[it.uploadError] = true; });
            var why = Object.keys(codes).join(', ');
            out.push(notice('warn', t('uploadFailed', { n: failed }), t('uploadFailedBody') + (why ? ' (' + why + ')' : '')));
        }
        else if (artwork.shortBy > 0) out.push(notice('warn', t('uploadFailed', { n: artwork.shortBy }), t('uploadFailedBody')));
        var groups = sizeGroups();
        if (groups.length > 1) {
            var body = el('div', { className: 'faclp__notice-body' }, [el('div', { html: '<strong>' + escapeHtml(t('mixedTitle', { n: groups.length })) + '</strong> ' + escapeHtml(t('mixedBody')) })]);
            var row = el('div', { className: 'faclp__groups' });
            groups.forEach(function (g) {
                var active = state.appliedGroupKey === g.key;
                var chip = el('button', { type: 'button', className: 'faclp__chipbtn' + (active ? ' faclp__chipbtn--active' : '') }, [el('span', { text: active ? t('applied') + ' — ' + fmtDims(g.wIn, g.hIn, units) : t('applyGroup', { dims: fmtDims(g.wIn, g.hIn, units) }) }), el('span', { className: 'faclp__chipbtn-count', text: '×' + g.count })]);
                chip.addEventListener('click', function () { state.appliedGroupKey = g.key; bridge.applyDims(g.wIn, g.hIn, state.units); bridge.syncQuantity(g.count); updateChrome(); });
                row.appendChild(chip);
            });
            body.appendChild(row);
            out.push(el('div', { className: 'faclp__notice', role: 'note' }, [svgIcon(ICONS.info, 'faclp__notice-ico'), body]));
        } else if (groups.length === 1 && !state.appliedGroupKey) {
            var qty = bridge.readQty();
            if (qty !== null && qty !== state.items.length) {
                var b = el('div', { className: 'faclp__notice-body' }, [el('div', { text: t('qtyMismatch', { qty: qty, n: state.items.length }) })]);
                var syncBtn = el('button', { type: 'button', className: 'faclp__chipbtn', text: t('syncNow') }); syncBtn.addEventListener('click', function () { syncCalculator(); updateChrome(); });
                b.appendChild(el('div', { className: 'faclp__groups' }, [syncBtn]));
                out.push(el('div', { className: 'faclp__notice', role: 'note' }, [svgIcon(ICONS.info, 'faclp__notice-ico'), b]));
            }
        }
        var ps = packStats();
        if (state.items.length && ps.maxLeftover >= 4) out.push(notice('info', '', t('leverageTip', { len: fmtLen(ps.maxLeftover, units, 1) })));
        return out;
    }

    var RULER_W = 34, RULER_H = 26, MIN_FEED_SHOWN = 10, PAD_BELOW = 6;
    function chooseTickStep(pxPerUnit, minLabelPx) { var steps = [1, 2, 5, 10, 20, 25, 50, 100]; for (var j = 0; j < steps.length; j++) if (steps[j] * pxPerUnit >= minLabelPx) return steps[j]; return steps[steps.length - 1]; }

    function layoutCanvas() {
        if (!dom.viewport || state.dragging) return;
        var prevScroll = dom.viewport.scrollTop;
        var grid = buildGrid();
        if (dom.grid && dom.grid.parentNode === dom.viewport) dom.viewport.replaceChild(grid, dom.grid); else dom.viewport.appendChild(grid);
        dom.grid = grid;
        applySelectionDecorations();
        dom.viewport.scrollTop = prevScroll;
    }
    function buildGrid() {
        var roll = currentRoll(), units = state.units; nodeById = Object.create(null);
        var innerW = Math.max(280, (dom.card.clientWidth || 600) - 2), trackW = innerW - RULER_W;
        var pxPerIn = Math.min(16, Math.max(6, trackW / roll.widthInches)), sheetW = roll.widthInches * pxPerIn;
        var feedUsed = feedUsedIn(), feedShown = Math.max(feedUsed + PAD_BELOW, MIN_FEED_SHOWN), sheetH = feedShown * pxPerIn + 1;
        var edgeIn = Math.max(0, (roll.widthInches - roll.usableInches) / 2), edgePx = edgeIn * pxPerIn;
        var isCm = units === 'centimeters', pxPerUnit = isCm ? pxPerIn / CM_PER_IN : pxPerIn;

        var grid = el('div', { className: 'faclp__grid' });
        grid.appendChild(el('div', { className: 'faclp__corner', text: isCm ? 'CM' : 'IN' }));
        var rulerX = el('div', { className: 'faclp__ruler-x', style: 'width:' + sheetW + 'px;height:' + RULER_H + 'px;background-image:repeating-linear-gradient(to right,#3a3a3a 0 1px,transparent 1px ' + pxPerUnit + 'px);background-position:0 bottom;background-size:' + pxPerUnit + 'px 6px;background-repeat:repeat-x;' });
        var stepX = chooseTickStep(pxPerUnit, 34), totalUnitsX = isCm ? roll.widthInches * CM_PER_IN : roll.widthInches;
        for (var u = 0; u <= totalUnitsX + EPS; u += stepX) rulerX.appendChild(el('span', { className: 'faclp__tick-label', style: 'left:' + (u * pxPerUnit) + 'px', text: fmtNum(u, 0) }));
        grid.appendChild(rulerX);
        var rulerY = el('div', { className: 'faclp__ruler-y', style: 'width:' + RULER_W + 'px;height:' + sheetH + 'px;background-image:repeating-linear-gradient(to bottom,#3a3a3a 0 1px,transparent 1px ' + pxPerUnit + 'px);background-position:right 0;background-size:6px ' + pxPerUnit + 'px;background-repeat:repeat-y;' });
        var stepY = chooseTickStep(pxPerUnit, 26), totalUnitsY = isCm ? feedShown * CM_PER_IN : feedShown;
        for (var v = 0; v <= totalUnitsY + EPS; v += stepY) rulerY.appendChild(el('span', { className: 'faclp__tick-label', style: 'top:' + (v * pxPerUnit) + 'px', text: fmtNum(v, 0) }));

        var sheetWrap = el('div', { className: 'faclp__sheet-wrap', style: 'width:' + sheetW + 'px' });
        var sheet = el('div', { className: 'faclp__sheet', style: 'width:' + sheetW + 'px;height:' + sheetH + 'px' });
        if (edgePx > 0.5) {
            var eL = el('div', { className: 'faclp__edge faclp__edge--left', style: 'width:' + edgePx + 'px' }), eR = el('div', { className: 'faclp__edge faclp__edge--right', style: 'width:' + edgePx + 'px' });
            if (edgePx > 16) { eL.appendChild(el('span', { className: 'faclp__edge-label', text: t('edgeLabel') })); eR.appendChild(el('span', { className: 'faclp__edge-label', text: t('edgeLabel') })); }
            sheet.appendChild(eL); sheet.appendChild(eR);
        }
        var printable = el('div', { className: 'faclp__printable', style: 'left:' + edgePx + 'px;width:' + (roll.usableInches * pxPerIn) + 'px;height:' + sheetH + 'px' });
        printable.appendChild(el('div', { className: 'faclp__printable-label', text: t('printableLabel', { w: fmtLen(roll.usableInches, units, 1) }) }));
        if (feedUsed > 0.05) {
            var markY = feedUsed * pxPerIn;
            printable.appendChild(el('div', { className: 'faclp__feedmark', style: 'top:' + markY + 'px' }, [el('span', { className: 'faclp__feedmark-label', text: t('feedUsedMark', { len: fmtLen(feedUsed, units, 1) }) })]));
            printable.appendChild(el('div', { className: 'faclp__unused-note', style: 'top:' + (markY + 4) + 'px', text: t('unusedRoll') }));
        }
        if (!state.items.length) {
            var drop = el('button', { type: 'button', className: 'faclp__drop' }, [svgIcon(ICONS.upload, 'faclp__drop-ico'), el('span', { html: '<strong>' + escapeHtml(t('dropHere')) + '</strong>' }), el('span', { text: t('dropSub') })]);
            drop.addEventListener('click', function () { dom.fileInput.click(); }); printable.appendChild(drop);
        }
        state.items.forEach(function (it) { printable.appendChild(buildPrint(it, pxPerIn, units)); });
        sheet.appendChild(printable); sheetWrap.appendChild(sheet); grid.appendChild(rulerY); grid.appendChild(sheetWrap);
        dom.printable = printable; dom.pxPerIn = pxPerIn;
        return grid;
    }
    function applyImgTransform(img, rotation, wPx, hPx) {
        img.style.position = 'absolute'; img.style.top = '50%'; img.style.left = '50%'; img.style.transformOrigin = 'center';
        if (rotation === 90 || rotation === 270) { img.style.width = hPx + 'px'; img.style.height = wPx + 'px'; } else { img.style.width = wPx + 'px'; img.style.height = hPx + 'px'; }
        img.style.transform = 'translate(-50%,-50%) rotate(' + rotation + 'deg)';
    }
    function buildPrint(it, pxPerIn, units) {
        var wPx = it.wIn * pxPerIn, hPx = it.hIn * pxPerIn;
        var wrap = el('div', { className: 'faclp__print', style: 'left:' + (it.xIn * pxPerIn) + 'px;top:' + (it.yIn * pxPerIn) + 'px;width:' + wPx + 'px;height:' + hPx + 'px', 'data-id': it.id, title: it.name + ' — ' + fmtDims(it.wIn, it.hIn, units) });
        var frame = el('div', { className: 'faclp__print-frame' });
        if (it.placeholder) {
            // No file behind this one: a plain grey panel whose only content is
            // the size it will print at, so the shopper reads dimensions off the
            // layout directly.
            wrap.classList.add('faclp__print--placeholder');
            frame.appendChild(el('span', { className: 'faclp__ph-dims', text: fmtDims(it.wIn, it.hIn, units) }));
            /*
             * Secondary control on the object itself: shows the state at a
             * glance and toggles it in place. The authoritative control lives in
             * the edit bar, because this one has to hide on prints too small to
             * hold it. pointerdown is swallowed so it never starts a drag.
             */
            var phLock = el('button', {
                type: 'button',
                className: 'faclp__ph-lock' + (it.aspectLocked ? ' faclp__ph-lock--on' : ''),
                title: it.aspectLocked ? t('aspectUnlock') : t('aspectLock'),
                'aria-label': it.aspectLocked ? t('aspectUnlock') : t('aspectLock'),
                'aria-pressed': it.aspectLocked ? 'true' : 'false'
            }, [svgIcon(it.aspectLocked ? ICONS.lockOn : ICONS.lockOff, 'faclp__ph-lock-ico')]);
            phLock.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
            phLock.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); toggleAspectLock(it.id); });
            frame.appendChild(phLock);
        } else {
            var img = el('img', { className: 'faclp__print-img', src: it.src, alt: '', draggable: 'false' });
            applyImgTransform(img, it.rotation, wPx, hPx); frame.appendChild(img);
        }
        wrap.appendChild(frame);
        if (!it.placeholder && wPx > 54 && hPx > 26) wrap.appendChild(el('span', { className: 'faclp__print-chip', text: fmtDims(it.wIn, it.hIn, units) }));
        if (isLowRes(it) && wPx > 66 && hPx > 40) wrap.appendChild(el('span', { className: 'faclp__print-flag', text: t('lowResFlag') }));
        // Upload overlay: dimmed veil, a bar across the print, and a percentage.
        // Hidden until this print's file is actually going up.
        var up = el('div', { className: 'faclp__uploading' }, [
            el('div', { className: 'faclp__uploading-pct' }),
            el('div', { className: 'faclp__uploading-track' }, [el('div', { className: 'faclp__uploading-fill' })])
        ]);
        wrap.appendChild(up);
        wrap.addEventListener('pointerdown', function (e) { onPrintPointerDown(e, it, wrap); });
        if (!it.placeholder) wrap.addEventListener('dblclick', function (e) { e.preventDefault(); triggerReplace(it.id); });
        nodeById[it.id] = wrap;
        if (isSelected(it.id)) wrap.classList.add('faclp__print--selected');
        return wrap;
    }
    /**
     * Paint upload progress onto the prints whose file is currently uploading.
     * Deliberately cheap: it only touches the overlay nodes, so it can be called
     * on every slice without rebuilding the canvas or disturbing a drag.
     */
    function renderUploadProgress() {
        var src = artwork.progressSrc, pct = Math.round((artwork.progress || 0) * 100);
        state.items.forEach(function (it) {
            var node = nodeById[it.id];
            if (!node) return;
            var up = node.querySelector('.faclp__uploading');
            if (!up) return;
            var active = (it.stashState === 'uploading' && it.src === src);
            node.classList.toggle('faclp__print--uploading', active);
            up.classList.toggle('faclp__uploading--on', active);
            if (!active) return;
            // A small print cannot fit a number and a bar; keep the bar.
            up.classList.toggle('faclp__uploading--compact', node.offsetHeight < 54 || node.offsetWidth < 90);
            var fill = up.querySelector('.faclp__uploading-fill');
            var label = up.querySelector('.faclp__uploading-pct');
            if (fill) fill.style.width = pct + '%';
            if (label) label.textContent = pct + '%';
        });
    }
    function onPrintPointerDown(e, it, wrap) {
        if (e.target.classList.contains('faclp__handle') || e.target.classList.contains('faclp__ghandle')) return;
        if (e.button !== undefined && e.button !== 0) return;
        if (e.shiftKey || e.ctrlKey || e.metaKey) { e.preventDefault(); e.stopPropagation(); toggleSelected(it.id); return; }
        if (!isSelected(it.id)) selectOnly(it.id);
        e.stopPropagation();
        startMove(e, it, wrap);
    }
    function updatePrintNode(node, it) {
        var p = dom.pxPerIn, wPx = it.wIn * p, hPx = it.hIn * p;
        node.style.left = (it.xIn * p) + 'px'; node.style.top = (it.yIn * p) + 'px'; node.style.width = wPx + 'px'; node.style.height = hPx + 'px';
        var img = node.querySelector('.faclp__print-img'); if (img) applyImgTransform(img, it.rotation, wPx, hPx);
        var chip = node.querySelector('.faclp__print-chip'); if (chip) chip.textContent = fmtDims(it.wIn, it.hIn, state.units);
        // Live readout inside a placeholder, updated on every resize step.
        var phd = node.querySelector('.faclp__ph-dims'); if (phd) phd.textContent = fmtDims(it.wIn, it.hIn, state.units);
    }

    // Selection chrome lives inside the printable layer and is rebuilt in place
    // whenever the selection changes — so it never triggers a canvas rebuild and
    // never scrolls the page. A single selection gets 8 resize handles; a
    // multi-selection gets one group box with corner handles that scale the set.
    function clearAllDecorations() {
        for (var id in nodeById) { var n = nodeById[id]; if (!n) continue; n.classList.remove('faclp__print--selected'); var kill = n.querySelectorAll('.faclp__handle'); for (var i = 0; i < kill.length; i++) kill[i].parentNode.removeChild(kill[i]); }
        if (dom.groupBox && dom.groupBox.parentNode) dom.groupBox.parentNode.removeChild(dom.groupBox); dom.groupBox = null;
    }
    function addResizeHandles(node, it) {
        ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(function (h) {
            var handle = el('span', { className: 'faclp__handle faclp__handle--' + h, 'data-h': h });
            handle.addEventListener('pointerdown', function (e) { e.stopPropagation(); if (e.button !== undefined && e.button !== 0) return; startResize(e, it, node, h); });
            node.appendChild(handle);
        });
    }
    function applySelectionDecorations() {
        clearAllDecorations();
        var sel = selectedItems();
        sel.forEach(function (it) { var n = nodeById[it.id]; if (n) n.classList.add('faclp__print--selected'); });
        if (sel.length === 1) { var n = nodeById[sel[0].id]; if (n) addResizeHandles(n, sel[0]); }
        else if (sel.length > 1) buildGroupBox();
    }
    function buildGroupBox() {
        if (!dom.printable) return;
        var box = el('div', { className: 'faclp__groupbox' });
        box.appendChild(el('span', { className: 'faclp__groupbox-count', text: t('selCountMany', { n: state.selectedIds.length }) }));
        ['nw', 'ne', 'se', 'sw'].forEach(function (h) {
            var handle = el('span', { className: 'faclp__ghandle faclp__ghandle--' + h, 'data-h': h, title: t('scaleLabel') });
            handle.addEventListener('pointerdown', function (e) { e.stopPropagation(); if (e.button !== undefined && e.button !== 0) return; startGroupScale(e, h); });
            box.appendChild(handle);
        });
        dom.printable.appendChild(box); dom.groupBox = box; positionGroupBox();
    }
    function positionGroupBox() {
        if (!dom.groupBox || !dom.pxPerIn) return;
        var sel = selectedItems(); if (sel.length < 2) return;
        var b = groupBounds(sel), p = dom.pxPerIn;
        dom.groupBox.style.left = (b.minX * p) + 'px'; dom.groupBox.style.top = (b.minY * p) + 'px';
        dom.groupBox.style.width = (b.w * p) + 'px'; dom.groupBox.style.height = (b.h * p) + 'px';
    }
    function startGroupScale(e, handle) {
        var rect = printableRect(), pxPerIn = dom.pxPerIn, usable = currentRoll().usableInches;
        var sel = selectedItems(), others = state.items.filter(function (x) { return !isSelected(x.id); });
        var b = groupBounds(sel), cx = b.cx, cy = b.cy;
        var base = sel.map(function (it) { return { id: it.id, it: it, xIn: it.xIn, yIn: it.yIn, wIn: it.wIn, hIn: it.hIn }; });
        var px0 = (e.clientX - rect.left) / pxPerIn, py0 = (e.clientY - rect.top) / pxPerIn;
        var startDist = Math.max(0.001, Math.sqrt((px0 - cx) * (px0 - cx) + (py0 - cy) * (py0 - cy)));
        var capNode = dom.groupBox || nodeById[sel[0].id];
        beginInteraction(capNode);
        try { capNode.setPointerCapture(e.pointerId); } catch (err) {}
        function move(ev) {
            var px = (ev.clientX - rect.left) / pxPerIn, py = (ev.clientY - rect.top) / pxPerIn;
            var factor = Math.max(0.05, Math.sqrt((px - cx) * (px - cx) + (py - cy) * (py - cy)) / startDist);
            var baseItems = base.map(function (o) { return { id: o.id, xIn: o.xIn, yIn: o.yIn, wIn: o.wIn, hIn: o.hIn }; });
            var res = computeGroupScaleAbout(baseItems, others, factor, cx, cy, usable);
            base.forEach(function (o) { var pn = res.pos[o.id]; if (pn) { o.it.xIn = pn.x; o.it.yIn = pn.y; o.it.wIn = pn.w; o.it.hIn = pn.h; var n = nodeById[o.id]; if (n) updatePrintNode(n, o.it); } });
            positionGroupBox();
        }
        function endScale() { capNode.removeEventListener('pointermove', move); capNode.removeEventListener('pointerup', endScale); capNode.removeEventListener('pointercancel', endScale); try { capNode.releasePointerCapture(e.pointerId); } catch (err) {} state.appliedGroupKey = null; finishInteraction(); }
        capNode.addEventListener('pointermove', move); capNode.addEventListener('pointerup', endScale); capNode.addEventListener('pointercancel', endScale);
    }
    function triggerReplace(id) { dom.replaceInput._targetId = id; dom.replaceInput.click(); }

    function printableRect() { return dom.printable.getBoundingClientRect(); }
    function startMove(e, it, node) {
        if (state.selectedIds.length > 1 && isSelected(it.id)) { startGroupMove(e, node); return; }
        var rect = printableRect(), pxPerIn = dom.pxPerIn;
        var startX = (e.clientX - rect.left) / pxPerIn, startY = (e.clientY - rect.top) / pxPerIn;
        var origin = { x: it.xIn, y: it.yIn, w: it.wIn, h: it.hIn }, lastValid = { x: it.xIn, y: it.yIn }, usable = currentRoll().usableInches;
        beginInteraction(node);
        try { node.setPointerCapture(e.pointerId); } catch (err) {}
        function tryPlace(x, y) { var cx = clamp(x, 0, Math.max(0, usable - it.wIn)), cy = Math.max(0, y); return overlapsAny(cx, cy, it.wIn, it.hIn, it.id) ? null : { x: cx, y: cy }; }
        function move(ev) {
            var tx = origin.x + ((ev.clientX - rect.left) / pxPerIn - startX), ty = origin.y + ((ev.clientY - rect.top) / pxPerIn - startY);
            var placed = tryPlace(tx, ty) || tryPlace(tx, lastValid.y) || tryPlace(lastValid.x, ty) || lastValid;
            it.xIn = placed.x; it.yIn = placed.y; lastValid = placed;
            node.style.left = (placed.x * pxPerIn) + 'px'; node.style.top = (placed.y * pxPerIn) + 'px';
        }
        function up() { node.removeEventListener('pointermove', move); node.removeEventListener('pointerup', up); node.removeEventListener('pointercancel', up); try { node.releasePointerCapture(e.pointerId); } catch (err) {} finishInteraction(); }
        node.addEventListener('pointermove', move); node.addEventListener('pointerup', up); node.addEventListener('pointercancel', up);
    }
    function startGroupMove(e, node) {
        var rect = printableRect(), pxPerIn = dom.pxPerIn, usable = currentRoll().usableInches;
        var startX = (e.clientX - rect.left) / pxPerIn, startY = (e.clientY - rect.top) / pxPerIn;
        var sel = selectedItems(), others = state.items.filter(function (x) { return !isSelected(x.id); });
        var origin = sel.map(function (it) { return { it: it, x: it.xIn, y: it.yIn }; });
        beginInteraction(node);
        try { node.setPointerCapture(e.pointerId); } catch (err) {}
        function move(ev) {
            var dx = (ev.clientX - rect.left) / pxPerIn - startX, dy = (ev.clientY - rect.top) / pxPerIn - startY;
            var base = origin.map(function (o) { return { id: o.it.id, xIn: o.x, yIn: o.y, wIn: o.it.wIn, hIn: o.it.hIn }; });
            var placed = computeGroupTranslate(base, others, dx, dy, usable);
            if (!placed) return;
            origin.forEach(function (o) { var p = placed[o.it.id]; if (p) { o.it.xIn = p.x; o.it.yIn = p.y; var n = nodeById[o.it.id]; if (n) { n.style.left = (p.x * pxPerIn) + 'px'; n.style.top = (p.y * pxPerIn) + 'px'; } } });
            positionGroupBox();
        }
        function up() { node.removeEventListener('pointermove', move); node.removeEventListener('pointerup', up); node.removeEventListener('pointercancel', up); try { node.releasePointerCapture(e.pointerId); } catch (err) {} finishInteraction(); }
        node.addEventListener('pointermove', move); node.addEventListener('pointerup', up); node.addEventListener('pointercancel', up);
    }
    function startResize(e, it, node, handle) {
        var rect = printableRect(), pxPerIn = dom.pxPerIn;
        var start = { x: it.xIn, y: it.yIn, w: it.wIn, h: it.hIn }, last = { x: it.xIn, y: it.yIn, w: it.wIn, h: it.hIn }, usable = currentRoll().usableInches;
        var phDims = node.querySelector('.faclp__ph-dims');
        if (phDims) phDims.textContent = fmtDims(it.wIn, it.hIn, state.units);
        var frameImg = node.querySelector('.faclp__print-img'), chip = node.querySelector('.faclp__print-chip');
        beginInteraction(node);
        try { node.setPointerCapture(e.pointerId); } catch (err) {}
        // Captured once so the shape cannot drift as the pointer moves.
        var ratio = aspectRatioFor(it), keepRatio = ratio > 0;
        function move(ev) {
            var pxIn = (ev.clientX - rect.left) / pxPerIn, pyIn = (ev.clientY - rect.top) / pxPerIn;
            var r = computeResize(handle, start, pxIn, pyIn, keepRatio ? ratio : 1, keepRatio, usable);
            if (overlapsAny(r.x, r.y, r.w, r.h, it.id)) r = last;
            last = r; it.xIn = r.x; it.yIn = r.y; it.wIn = r.w; it.hIn = r.h;
            var wPx = r.w * pxPerIn, hPx = r.h * pxPerIn;
            node.style.left = (r.x * pxPerIn) + 'px'; node.style.top = (r.y * pxPerIn) + 'px'; node.style.width = wPx + 'px'; node.style.height = hPx + 'px';
            if (frameImg) applyImgTransform(frameImg, it.rotation, wPx, hPx);
            if (chip) chip.textContent = fmtDims(r.w, r.h, state.units);
            if (phDims) phDims.textContent = fmtDims(r.w, r.h, state.units);
        }
        function up() { node.removeEventListener('pointermove', move); node.removeEventListener('pointerup', up); node.removeEventListener('pointercancel', up); try { node.releasePointerCapture(e.pointerId); } catch (err) {} state.appliedGroupKey = null; finishInteraction(); }
        node.addEventListener('pointermove', move); node.addEventListener('pointerup', up); node.addEventListener('pointercancel', up);
    }
    function beginInteraction(node) { state.dragging = true; dom.container.classList.add('faclp--interacting'); node.classList.add('faclp__print--dragging'); }
    function finishInteraction() { state.dragging = false; dom.container.classList.remove('faclp--interacting'); afterItemsChanged(true); }

    function refreshFromCalculator() {
        var changed = false, rollKey = bridge.readRollKey();
        if (rollKey && rollKey !== state.rollKey) {
            state.rollKey = rollKey; var usable = currentRoll().usableInches;
            state.items.forEach(function (it) { if (it.wIn > usable) { var f = usable / it.wIn; it.wIn = usable; it.hIn *= f; } it.xIn = clamp(it.xIn, 0, Math.max(0, usable - it.wIn)); });
            state.items.forEach(function (it) { if (overlapsAny(it.xIn, it.yIn, it.wIn, it.hIn, it.id)) autoPlace(it); });
            changed = true;
        }
        var units = bridge.readUnits(); if (units !== state.units) { state.units = units; changed = true; }
        if (changed) { updateChrome(); layoutCanvas(); }
        pushLayoutFeed();
    }
    /**
     * Mirror the calculator's price into the planner.
     *
     * The shopper works in the planner but the price is rendered above it, so
     * arranging prints meant scrolling back up to see what it cost. This puts
     * the same figure — read from the calculator, never recomputed — in the
     * planner too, and keeps it in view while they work.
     */
    function updatePrice() {
        if (!dom.pricebar) return;
        var val = dom.pricebar.querySelector('.faclp__pricebar-value');
        var per = dom.pricebar.querySelector('.faclp__pricebar-per');
        var p = bridge.readPrice();
        if (!p) { dom.pricebar.classList.add('faclp__pricebar--empty'); return; }
        dom.pricebar.classList.remove('faclp__pricebar--empty');
        dom.pricebar.classList.toggle('faclp__pricebar--error', !!p.error);
        val.textContent = p.text;
        per.textContent = p.error ? '' : (p.per || '');
    }
    function watchPrice(root) {
        // characterData: React rewrites the amount in place, which is neither a
        // childList nor an attribute change.
        var pending = false;
        var obs = new MutationObserver(function () {
            if (pending) return;
            pending = true;
            window.setTimeout(function () { pending = false; updatePrice(); }, 60);
        });
        obs.observe(root, { subtree: true, childList: true, characterData: true });
        updatePrice();
    }
    function watchCalculator(root) {
        var pending = false;
        var observer = new MutationObserver(function () {
            if (bridge.internalWrite || pending || state.dragging) return;
            pending = true;
            window.setTimeout(function () { pending = false; refreshFromCalculator(); if (state.items.length && !state.dragging) updateChrome(); }, 120);
        });
        observer.observe(root, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'value'] });
        document.addEventListener('input', function (e) { if (bridge.internalWrite) return; if (e.target && e.target.id === 'quantity-input-field' && state.items.length) { updateChrome(); window.setTimeout(pushLayoutFeed, 0); } }, true);
    }

    /* ----------------------------------------------------------------
       Artwork stash. As prints are placed, their source images are
       uploaded to the site (deduped by source, capped by size) and a
       manifest is kept in the WooCommerce session. At checkout the server
       attaches those files to the order so the studio can download exactly
       what the customer arranged. Uploading is best-effort: if it fails,
       the planner keeps working locally and the customer can still send the
       print-ready masters through the WeTransfer link after checkout.
    ---------------------------------------------------------------- */
    function round3(n) { return Math.round(n * 1000) / 1000; }
    function safeName(name) { return String(name || 'artwork').replace(/[^\w.\-]+/g, '_').slice(-120) || 'artwork'; }
    var artwork = {
        cfg: null, timer: null, seq: 0, busy: false, again: false, reclicking: false, uploading: 0, shortBy: 0, progress: 0, progressLabel: '', progressSrc: '',
        init: function () {
            var c = window.fac_artwork;
            if (c && typeof c === 'object' && c.ajaxUrl && c.nonce) {
                this.cfg = {
                    ajaxUrl: String(c.ajaxUrl), nonce: String(c.nonce),
                    maxBytes: parseInt(c.maxBytes, 10) || 0,
                    maxFiles: parseInt(c.maxFiles, 10) || 0,
                    chunkBytes: parseInt(c.chunkBytes, 10) || (4 * 1024 * 1024)
                };
            }
        },
        enabled: function () { return !!(this.cfg && this.cfg.ajaxUrl && this.cfg.nonce); },
        schedule: function () { if (!this.enabled()) return; var self = this; clearTimeout(this.timer); this.timer = window.setTimeout(function () { self.sync(); }, 700); },
        blobFor: function (it) { if (it.file) return Promise.resolve(it.file); return fetch(it.src).then(function (r) { return r.blob(); }).catch(function () { return null; }); },
        manifestOf: function () {
            return state.items.map(function (it) {
                // xIn/yIn are the print's position on the roll. The order screen
                // redraws the arrangement from these, so production sees the
                // same layout the customer built.
                return { name: String(it.name || '').slice(0, 200), wIn: round3(it.wIn), hIn: round3(it.hIn), xIn: round3(it.xIn), yIn: round3(it.yIn), rotation: it.rotation || 0, stashId: it.stashId || '', upload: '', placeholder: it.placeholder ? 1 : 0, phId: it.placeholder ? String(it.phId || '') : '' };
            });
        },
        rollOf: function () {
            var r = currentRoll();
            return { key: String(r.key || ''), usableIn: round3(r.usableInches || 0), widthIn: round3(r.widthInches || 0) };
        },
        randomId: function () {
            var hex = '', bytes;
            if (window.crypto && window.crypto.getRandomValues) {
                bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
                for (var i = 0; i < bytes.length; i++) hex += ('0' + bytes[i].toString(16)).slice(-2);
                return hex;
            }
            while (hex.length < 32) hex += Math.floor(Math.random() * 16).toString(16);
            return hex.slice(0, 32);
        },
        /**
         * Send one image to the server in sequential slices and resolve with the
         * stash id it was stored under. Slices are sized to what this server
         * actually accepts, so a file far larger than upload_max_filesize still
         * gets through — nothing depends on the whole image fitting in one POST.
         */
        /* Errors worth another attempt. Anything else is a decision the server
           has already made and retrying only wastes the shopper's time. */
        retriable: function (code) {
            return code !== 'bad_type' && code !== 'too_large' && code !== 'session_full' &&
                   code !== 'too_many_files' && code !== 'bad_nonce' && code !== 'fac_no_session';
        },
        wait: function (ms) { return new Promise(function (r) { window.setTimeout(r, ms); }); },
        /**
         * Send one image to the server in sequential slices and resolve with the
         * stash id it was stored under.
         *
         * A large master is dozens of requests, so a single hiccup — a dropped
         * connection, a proxy hiccup, a host rate-limiting a burst of uploads —
         * must not lose the file. Failed slices are retried with backoff, and if
         * the server reports it is at a different position the upload resumes
         * from there rather than starting over.
         */
        uploadBlob: function (blob, name, onProgress) {
            var self = this, chunk = Math.max(65536, this.cfg.chunkBytes);
            var total = Math.max(1, Math.ceil(blob.size / chunk)), uploadId = this.randomId();
            var MAX_ATTEMPTS = 5, MAX_RESUMES = 10, resumes = 0;

            function sendSlice(index, attempt) {
                var from = index * chunk, slice = blob.slice(from, Math.min(from + chunk, blob.size));
                var fd = new FormData();
                fd.append('action', 'fac_layout_chunk');
                fd.append('nonce', self.cfg.nonce);
                fd.append('uploadId', uploadId);
                fd.append('index', String(index));
                fd.append('total', String(total));
                fd.append('size', String(blob.size));
                fd.append('name', safeName(name));
                fd.append('chunk', slice, 'chunk');

                return fetch(self.cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) {
                        return r.json().then(function (j) { return j; }, function () { return { success: false, data: { message: 'http_' + r.status } }; });
                    })
                    .then(function (json) {
                        if (json && json.success && json.data) {
                            if (json.data.complete) return String(json.data.stashId || '');
                            if (onProgress) onProgress(Math.min(1, (index + 1) / total));
                            return sendSlice(index + 1, 0);
                        }
                        var code = (json && json.data && json.data.message) ? String(json.data.message) : 'upload_failed';
                        /* The server knows which slice it wants next; trust it. */
                        if ('out_of_order' === code && json.data && typeof json.data.expected === 'number' && resumes < MAX_RESUMES) {
                            resumes++;
                            return sendSlice(json.data.expected, 0);
                        }
                        var err = new Error(code); err.code = code; throw err;
                    })
                    .catch(function (err) {
                        var code = (err && err.code) ? err.code : 'network';
                        if (!self.retriable(code) || attempt + 1 >= MAX_ATTEMPTS) {
                            var out = new Error(code); out.code = code; throw out;
                        }
                        // 0.5s, 1s, 2s, 4s — enough to ride out a rate limiter.
                        return self.wait(500 * Math.pow(2, attempt)).then(function () { return sendSlice(index, attempt + 1); });
                    });
            }
            return sendSlice(0, 0);
        },
        /**
         * Upload anything not yet stored, then post the manifest.
         *
         * Only one run is ever in flight. Overlapping runs used to post manifests
         * built from stale state, and the server — which treats a manifest as the
         * whole truth — dropped the prints whose ids had not come back yet and
         * deleted their freshly uploaded files. Duplicates were hit hardest,
         * since several prints share a single file.
         */
        sync: function () {
            if (!this.enabled()) return Promise.resolve();
            if (this.busy) { this.again = true; return Promise.resolve(); }
            var self = this;
            this.busy = true;

            // One upload per distinct source image; duplicates ride along.
            var pending = [], seen = {};
            state.items.forEach(function (it) {
                if (it.placeholder || it.stashId) return;   // nothing to send for a placeholder
                // A previous failure is retried on the next save rather than
                // written off, but not forever.
                if (it.stashState === 'failed' && (it.uploadTries || 0) >= 3) return;
                if (!(it.src in seen)) { seen[it.src] = true; pending.push(it); }
            });

            pending.forEach(function (it) { it.stashState = 'uploading'; it.uploadTries = (it.uploadTries || 0) + 1; });
            if (pending.length) { this.uploading = pending.length; this.progress = 0; updateChrome(); }

            function uploadNext(i) {
                if (i >= pending.length) return Promise.resolve();
                var it = pending[i];
                return self.blobFor(it).then(function (blob) {
                    if (!blob) throw new Error('no_blob');
                    if (self.cfg.maxBytes && blob.size > self.cfg.maxBytes) throw new Error('too_large');
                    self.progressLabel = it.name;
                    self.progressSrc = it.src;
                    self.progress = 0;
                    renderUploadProgress();
                    return self.uploadBlob(blob, it.name, function (frac) { self.progress = frac; renderUploadProgress(); updateNoticesOnly(); });
                }).then(function (stashId) {
                    if (stashId) {
                        // Apply to every print sharing this source, duplicates included.
                        state.items.forEach(function (o) { if (o.src === it.src && !o.stashId) { o.stashId = stashId; o.stashState = 'done'; } });
                    }
                }).catch(function (err) {
                    var code = (err && err.code) ? err.code : 'upload_failed';
                    state.items.forEach(function (o) { if (o.src === it.src && !o.stashId) { o.stashState = 'failed'; o.uploadError = code; } });
                }).then(function () {
                    self.uploading = Math.max(0, self.uploading - 1);
                    return uploadNext(i + 1);
                });
            }

            return uploadNext(0).then(function () {
                // Every id is known by now, so this manifest is complete.
                var fd = new FormData();
                fd.append('action', 'fac_layout_save');
                fd.append('nonce', self.cfg.nonce);
                fd.append('manifest', JSON.stringify(self.manifestOf()));
                fd.append('roll', JSON.stringify(self.rollOf()));
                var sent = state.items.length;
                return fetch(self.cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return null; }); })
                    .then(function (json) {
                        // Verify the server actually kept what was sent. Silent
                        // divergence here is what put orders through with no
                        // artwork attached, so it is now surfaced immediately.
                        var stored = (json && json.data && typeof json.data.stored === 'number') ? json.data.stored : null;
                        self.shortBy = (stored === null || !json || !json.success) ? 0 : Math.max(0, sent - stored);
                    })
                    .catch(function () { self.shortBy = 0; });
            }).catch(function () {}).then(function () {
                self.busy = false;
                self.uploading = 0;
                self.progress = 0;
                self.progressLabel = '';
                self.progressSrc = '';
                self.blockedCheckout = false;
                updateChrome();
                renderUploadProgress();
                if (self.again) { self.again = false; self.schedule(); }
            });
        },
        /**
         * Best-effort save on the way out. Marked partial so the server merges
         * rather than treating it as the authoritative picture — a flush can
         * race a running sync and must never be able to remove anything.
         */
        flush: function () {
            if (!this.enabled()) return;
            if (this.busy) return;               // the running sync will post a fuller manifest
            clearTimeout(this.timer);
            try {
                var fd = new FormData();
                fd.append('action', 'fac_layout_save');
                fd.append('nonce', this.cfg.nonce);
                fd.append('manifest', JSON.stringify(this.manifestOf()));
                fd.append('roll', JSON.stringify(this.rollOf()));
                fd.append('partial', '1');
                fetch(this.cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, keepalive: true }).catch(function () {});
            } catch (e) {}
        },
        pendingCount: function () { return state.items.filter(function (it) { return !it.placeholder && !it.stashId && it.stashState !== 'failed'; }).length; },
        failedCount: function () { return state.items.filter(function (it) { return !it.placeholder && it.stashState === 'failed'; }).length; },
        placeholderCount: function () { return state.items.filter(function (it) { return !!it.placeholder; }).length; }
    };
    function bindArtworkFlush() {
        if (!artwork.enabled()) return;
        window.addEventListener('pagehide', function () { artwork.flush(); });
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') artwork.flush(); });
        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest && e.target.closest('#add-to-cart-primary-btn');
            if (!b) return;
            /* Checking out mid-upload is how a large master goes missing from an
               order: the manifest is posted only once every file is stored. Hold
               the click, say why, and let them through as soon as it lands. */
            if (artwork.busy && artwork.uploading > 0) {
                e.preventDefault(); e.stopPropagation();
                artwork.blockedCheckout = true;
                updateChrome();
                if (dom.noticesWrap && dom.noticesWrap.scrollIntoView) dom.noticesWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            /* The price now depends on the arrangement the *server* holds, and
               the manifest is posted on a debounce. Checking out inside that
               window would price the order from the previous layout — and the
               cart endpoint refuses a price it disagrees with. So land the
               manifest first, then put the click back through. */
            if (artwork.timer && !artwork.reclicking) {
                e.preventDefault(); e.stopPropagation();
                window.clearTimeout(artwork.timer); artwork.timer = null;
                artwork.reclicking = true;
                var resume = function () {
                    artwork.reclicking = false;
                    b.click();
                };
                var p = artwork.sync();
                if (p && p.then) p.then(resume, resume); else resume();
                return;
            }
            artwork.flush();
        }, true);
    }


    function boot() {
        var container = document.getElementById('fac-layout-planner');
        if (!container) return;
        if (window.__FAC_BOOTSTRAP_ERROR) return;
        var quote = window.__FAC_QUOTE; if (quote && (quote.locked === true || quote.customPriced === true)) return;
        var tries = 0;
        (function waitForCalc() {
            var root = document.getElementById('root'), qty = document.getElementById('quantity-input-field');
            if (root && qty) {
                bridge.root = root; state.rollKey = bridge.readRollKey() || getRolls()[0].key; state.units = bridge.readUnits();
                buildSkeleton(container); watchCalculator(root); watchPrice(root); updateChrome(); layoutCanvas(); artwork.init(); bindArtworkFlush(); return;
            }
            if (++tries < 200) window.setTimeout(waitForCalc, 50);
        })();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

    window.FACLP = { pack: pack, computeArrange: computeArrange, computeMove: computeMove, computeResize: computeResize, effectivePpi: effectivePpi, toDisplay: toDisplay, fromDisplay: fromDisplay, chooseTickStep: chooseTickStep, groupBounds: groupBounds, computeGroupTranslate: computeGroupTranslate, computeGroupScaleAbout: computeGroupScaleAbout, _state: state, _addItem: addItem };
})();
