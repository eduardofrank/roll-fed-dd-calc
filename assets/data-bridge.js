/**
 * Roll Fed Calc — WordPress Data Bridge
 *
 * Runs BEFORE calculator.js. Reads window.facData (set by wp_localize_script)
 * and exposes branch-specific data for the React bundle.
 */
(function () {
    'use strict';

    if (typeof window.facData === 'undefined') return;

    var d = window.facData;
    window.__FAC_LANG = d.lang || 'en';
    var mode = d.calculatorType === 'inkjet' ? 'inkjet' : 'archival';
    var images = d.paperImages || {};
    var bootstrapErrors = [];

    window.__FAC_CALCULATOR_MODE = mode;
    window.__FAC_BOOTSTRAP_ERROR = '';

    function addError(message) {
        bootstrapErrors.push(message);
    }

    if (mode === 'inkjet') {
        if (!Array.isArray(d.paperData)) {
            addError('Inkjet paper data is missing or invalid.');
            window.__FAC_INKJET_PAPER_DATA = [];
        } else {
            window.__FAC_INKJET_PAPER_DATA = d.paperData;
        }
    } else if (d.paperData && typeof d.paperData === 'object' && !Array.isArray(d.paperData)) {
        Object.keys(d.paperData).forEach(function (brand) {
            var finishes = d.paperData[brand];
            if (!finishes) return;
            Object.keys(finishes).forEach(function (finish) {
                var papers = finishes[finish];
                if (!Array.isArray(papers)) return;
                papers.forEach(function (paper) {
                    if (!paper.slug) return;
                    var override = images[paper.slug];
                    if (override && override !== '') {
                        paper.imageUrl = override;
                    }
                    if (paper.imageUrl === '' || paper.imageUrl === undefined) {
                        delete paper.imageUrl;
                    }
                });
            });
        });
        window.__FAC_PAPER_DATA = d.paperData;
    } else {
        addError('Archival paper data is missing or invalid.');
        window.__FAC_PAPER_DATA = {};
    }

    if (Array.isArray(d.rollWidths) && d.rollWidths.length > 0) {
        window.__FAC_ROLL_WIDTHS = d.rollWidths;
    } else {
        addError('Roll widths are missing.');
    }

    if (d.mountingRates && typeof d.mountingRates === 'object') {
        window.__FAC_MOUNTING_RATES = d.mountingRates;
    } else {
        addError('Mounting rates are missing.');
    }

    if (d.turnaroundRates && typeof d.turnaroundRates === 'object') {
        window.__FAC_TURNAROUND_RATES = d.turnaroundRates;
    } else {
        addError('Turnaround rates are missing.');
    }

    window.wp_paper_images = images;

    if (d.ajaxUrl) {
        window.wp_ajax_url = d.ajaxUrl;
    } else {
        addError('WordPress AJAX endpoint is missing.');
    }

    if (d.wooProductId) {
        window.woocommerce_product_id = d.wooProductId;
    } else {
        addError('WooCommerce product is not configured.');
    }

    if (d.nonce) {
        window.fac_nonce = d.nonce;
    } else {
        addError('Security nonce is missing.');
    }

    /*
     * Quote links.
     *
     * A malformed payload used to be treated as "no quote", which meant a shape
     * mismatch between here and fac_quote_build_js_payload() degraded silently
     * into an ordinary calculator — the link looked like it worked and quietly
     * sold the wrong thing. A token that arrives but can't be read is a bug, so
     * say so loudly instead.
     */
    if (d.quote === null || typeof d.quote === 'undefined') {
        window.__FAC_QUOTE = null;
    } else if (
        typeof d.quote === 'object' &&
        typeof d.quote.token === 'string' &&
        Array.isArray(d.quote.items) &&
        d.quote.items.length > 0
    ) {
        window.__FAC_QUOTE = {
            token: d.quote.token,
            label: typeof d.quote.label === 'string' ? d.quote.label : '',
            items: d.quote.items,
            locked: d.quote.locked === true,
            customPriced: d.quote.customPriced === true,
            totalOverride: typeof d.quote.totalOverride === 'number' ? d.quote.totalOverride : null,
            price: Number(d.quote.price) || 0
        };
    } else {
        window.__FAC_QUOTE = null;
        addError('This quote link could not be read.');
    }

    window.__FAC_QUOTE_NOTICE = typeof d.quoteNotice === 'string' ? d.quoteNotice : '';

    // Quote authoring mode. Present only when the server decided this visitor is
    // an administrator who asked for it; absent for every other request.
    window.__FAC_QUOTE_ADMIN =
        d.quoteAdmin && typeof d.quoteAdmin === 'object' && d.quoteAdmin.active === true
            ? d.quoteAdmin
            : null;

    if (bootstrapErrors.length) {
        window.__FAC_BOOTSTRAP_ERROR = bootstrapErrors.join(' ');
    }

})();
