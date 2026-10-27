/**
 * spin-wheel.js  —  Spin to Win Gift  v2.2.0
 * ─────────────────────────────────────────────────────────────────────────────
 * Requires:  D3 v7, canvas-confetti (both enqueued before this file)
 * Data:      window.spinData  (injected by STWG_Frontend via wp_localize_script)
 *
 * DESIGN PRINCIPLES:
 *  • Backend is the ONLY source of truth – no Math.random() anywhere.
 *  • Frontend only: draws the wheel, sends AJAX, animates to backend result.
 *  • All state transitions go through the StateMachine below.
 *  • Every DOM mutation is channelled through a single render() call.
 * ─────────────────────────────────────────────────────────────────────────────
 */

/* ─── Guard ─────────────────────────────────────────────────────────────── */
if ( typeof window.spinData === 'undefined' ) {
    console.error( '[STWG] window.spinData missing – is wp_localize_script working?' );
}

( function ( d3, confetti, cfg ) {
    'use strict';

    if ( ! cfg ) { return; }

    /* ═══════════════════════════════════════════════════════════════════════
       CONSTANTS & CONFIG
    ═══════════════════════════════════════════════════════════════════════ */
    const GIFTS         = Array.isArray( cfg.gifts ) ? cfg.gifts : []; // no weights
    const AJAX_URL      = cfg.ajax_url;
    const MODE          = cfg.mode || 'order';
    const ORDER_ID      = cfg.order_id;
    const ORDER_KEY     = cfg.order_key;
    const CHECKOUT_EMAIL = cfg.checkout_email || '';
    const NONCE         = cfg.nonce;
    const I18N          = cfg.i18n || {};

    if ( ! d3 || typeof d3.select !== 'function' || typeof d3.arc !== 'function' ) {
        const msg = document.getElementById( 'stwg-msg' );
        const spinBtn = document.getElementById( 'stwg-spin-btn' );
        if ( spinBtn ) { spinBtn.disabled = true; }
        if ( msg ) {
            msg.textContent = I18N.error_generic || 'The prize wheel could not load. Please refresh the page.';
            msg.classList.add( 'is-error' );
        }
        console.error( '[STWG] D3 unavailable; wheel UI disabled.' );
        return;
    }

    const NUM_SLICES    = GIFTS.length;
    const SLICE_DEG     = NUM_SLICES ? 360 / NUM_SLICES : 360;

    // Wheel geometry — compact modal (cap width, viewport height, and absolute max)
    const vwCap         = window.innerWidth - 48;
    const vhCap         = Math.round( window.innerHeight * 0.36 );
    const SIZE          = Math.max( 200, Math.min( 368, vwCap, vhCap ) );
    const CX            = SIZE / 2;
    const CY            = SIZE / 2;
    const OUTER_R       = SIZE / 2 - 12;
    const INNER_R       = Math.max( 30, Math.min( 44, Math.round( SIZE * 0.0875 ) ) );

    // Animation
    const SPIN_ROTATIONS = 6;       // full extra rotations before landing
    const SPIN_DURATION  = 5000;    // ms – total spin animation time
    const EASE_FN        = d3.easeCubicOut;

    // Colour palette – deterministic by index (no randomness), admin-managed.
    const THEME = ( cfg.theme && typeof cfg.theme === 'object' ) ? cfg.theme : {};
    function themeHex( key, fallback ) {
        const s = typeof THEME[ key ] === 'string' ? THEME[ key ].trim() : '';
        return /^#[0-9a-f]{6}$/i.test( s ) ? s : fallback;
    }
    function hexToRgba( hex, alpha ) {
        const h = String( hex || '' ).replace( '#', '' ).trim().toLowerCase();
        let full = h;
        if ( full.length === 3 && /^[0-9a-f]{3}$/.test( full ) ) {
            full = full[0] + full[0] + full[1] + full[1] + full[2] + full[2];
        }
        if ( ! /^[0-9a-f]{6}$/.test( full ) ) {
            return 'rgba(0,0,0,' + alpha + ')';
        }
        const r = parseInt( full.slice( 0, 2 ), 16 );
        const g = parseInt( full.slice( 2, 4 ), 16 );
        const b = parseInt( full.slice( 4, 6 ), 16 );
        const a = Math.max( 0, Math.min( 1, Number( alpha ) || 0 ) );
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    }
    const FALLBACK_PALETTE = [
        '#35A393', '#54A79B', '#857750', '#7A7A7A', '#F3F3F3', '#F8F3ED',
    ];
    const PALETTE = parsePalette( cfg.theme?.section_colors ) || FALLBACK_PALETTE;
    const stwgAccent = themeHex( 'primary_button', PALETTE[0] );
    const stwgAccent2 = themeHex( 'claim_button', stwgAccent );
    const hubFill = themeHex( 'popup_bg', '#F8F3ED' );
    const wheelShadowRgba = hexToRgba( themeHex( 'wheel_shadow', '#7A7A7A' ), 0.52 );
    const CENTER_LOGO_URL = typeof cfg.center_logo_url === 'string' ? cfg.center_logo_url.trim() : '';

    /* ═══════════════════════════════════════════════════════════════════════
       STATE MACHINE
       States: idle | loading | spinning | won | claiming | claimed | error | already_spun
    ═══════════════════════════════════════════════════════════════════════ */
    const STATE = {
        current:  'idle',
        giftId:   null,
        outcomeId: null,
        giftLabel: null,
        outcomeType: 'product',
        giftValueHtml: '',
        canClaim: true,

        set( next, data = {} ) {
            this.current = next;
            Object.assign( this, data );
            render( this );
        },
    };

    /* ═══════════════════════════════════════════════════════════════════════
       DOM REFS
    ═══════════════════════════════════════════════════════════════════════ */
    const $ = id => document.getElementById( id );
    const els = {
        launcher    : $( 'stwg-launcher' ),
        openModal   : $( 'stwg-open-modal' ),
        modal       : $( 'stwg-modal' ),
        closeModal  : $( 'stwg-close-modal' ),
        modalBackdrop: $( 'stwg-modal-backdrop' ),
        persistentNote: $( 'stwg-persistent-note' ),
        gateNotice        : $( 'stwg-gate-notice' ),
        gateNoticeEyebrow : $( 'stwg-gate-notice-eyebrow' ),
        gateNoticeTitle   : $( 'stwg-gate-notice-title' ),
        gateNoticeBody    : $( 'stwg-gate-notice-body' ),
        gateNoticeScroll  : $( 'stwg-gate-notice-scroll' ),
        spinBtn     : $( 'stwg-spin-btn'    ),
        claimBtn    : $( 'stwg-claim-btn'   ),
        reclaimBtn  : $( 'stwg-reclaim-btn' ),
        claimEmailNote  : $( 'stwg-claim-email-note' ),
        reclaimEmailNote: $( 'stwg-reclaim-email-note' ),
        firstOrderConfirm: $( 'stwg-first-order-confirm' ),
        reclaimFirstOrderConfirm: $( 'stwg-reclaim-first-order-confirm' ),
        result      : $( 'stwg-result'      ),
        resultNote  : $( 'stwg-result-note' ),
        prizeLabel  : $( 'stwg-prize-label' ),
        prizeValue  : $( 'stwg-prize-value' ),
        alreadyPrizeValue: $( 'stwg-already-prize-value' ),
        alreadySpun : $( 'stwg-already-spun'),
        alreadyMsg  : $( 'stwg-already-msg' ),
        loadingOvl  : $( 'stwg-loading-overlay' ),
        msg         : $( 'stwg-msg'         ),
    };

    let gatePulseTimer = null;
    let gateFlashTimer = null;
    let gateNoticeShownKind = null;

    function triggerGateAttention() {
        if ( ! els.gateNotice ) { return; }
        els.gateNotice.classList.remove( 'stwg-gate-notice--flash' );
        if ( gateFlashTimer ) { clearTimeout( gateFlashTimer ); }
        void els.gateNotice.offsetWidth;
        els.gateNotice.classList.add( 'stwg-gate-notice--flash' );
        gateFlashTimer = setTimeout( () => {
            els.gateNotice?.classList.remove( 'stwg-gate-notice--flash' );
            gateFlashTimer = null;
        }, 2600 );
    }

    function pulseGateNotice() {
        if ( ! els.gateNotice || els.gateNotice.style.display === 'none' ) { return; }
        els.gateNotice.classList.remove( 'is-pulse' );
        if ( gatePulseTimer ) { clearTimeout( gatePulseTimer ); }
        void els.gateNotice.offsetWidth;
        els.gateNotice.classList.add( 'is-pulse' );
        gatePulseTimer = setTimeout( () => {
            els.gateNotice?.classList.remove( 'is-pulse' );
            gatePulseTimer = null;
        }, 700 );
    }

    function focusScrollField( el ) {
        if ( ! el || typeof el.focus !== 'function' ) { return; }
        try {
            el.focus( { preventScroll: false } );
        } catch ( e ) {
            el.focus();
        }
        el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
    }

    /** Scroll to email or first empty required billing field. */
    function scrollToCheckoutIssue() {
        const email = getCheckoutEmail();
        if ( MODE !== 'order' && ! isValidCheckoutEmail( email ) ) {
            const emailEl = document.getElementById( 'billing_email' );
            if ( emailEl ) {
                focusScrollField( emailEl );
                return;
            }
        }
        scrollToBillingDetails();
    }

    function syncGateNotice( emailBad, billingBad, gateOk ) {
        if ( ! els.gateNotice ) { return; }
        if ( els.persistentNote && els.persistentNote.style.display !== 'none' && String( els.persistentNote.textContent || '' ).trim() ) {
            show( els.gateNotice, false );
            els.openModal?.removeAttribute( 'aria-describedby' );
            gateNoticeShownKind = null;
            return;
        }
        if ( MODE !== 'checkout_test' ) {
            show( els.gateNotice, false );
            els.openModal?.removeAttribute( 'aria-describedby' );
            gateNoticeShownKind = null;
            return;
        }
        if ( gateOk ) {
            show( els.gateNotice, false );
            els.openModal?.removeAttribute( 'aria-describedby' );
            gateNoticeShownKind = null;
            return;
        }
        const kind = emailBad ? 'email' : 'billing';
        const kindChanged = gateNoticeShownKind !== kind;
        gateNoticeShownKind = kind;

        if ( els.gateNoticeEyebrow ) {
            if ( emailBad ) {
                els.gateNoticeEyebrow.textContent = I18N.gate_notice_eyebrow_email || 'STEP 1 · EMAIL';
            } else {
                els.gateNoticeEyebrow.textContent = I18N.gate_notice_eyebrow_billing || 'STEP 2 · CHECKOUT';
            }
        }
        if ( els.gateNoticeTitle && els.gateNoticeBody && els.gateNoticeScroll ) {
            if ( emailBad ) {
                els.gateNoticeTitle.textContent = I18N.gate_notice_email_title || 'Your gift is almost yours';
                els.gateNoticeBody.textContent = I18N.gate_notice_email_body || '';
                els.gateNoticeScroll.textContent = I18N.gate_notice_email_cta || 'Go to email field';
            } else if ( billingBad ) {
                els.gateNoticeTitle.textContent = I18N.gate_notice_billing_title || 'Almost there';
                els.gateNoticeBody.textContent = I18N.gate_notice_billing_body || '';
                els.gateNoticeScroll.textContent = I18N.gate_notice_billing_cta || 'Go to checkout fields';
            }
        }
        show( els.gateNotice, true );
        els.openModal?.setAttribute( 'aria-describedby', 'stwg-gate-notice' );
        if ( kindChanged ) {
            triggerGateAttention();
        }
    }

    function isModalOpen() {
        return Boolean( els.modal && els.modal.style.display !== 'none' );
    }

    function openModal() {
        const email = getCheckoutEmail();
        const needEmail = MODE !== 'order';
        if ( needEmail && ! isValidCheckoutEmail( email ) ) {
            scrollToCheckoutIssue();
            pulseGateNotice();
            return;
        }
        if ( MODE === 'checkout_test' && ! isBillingSectionComplete() ) {
            scrollToCheckoutIssue();
            pulseGateNotice();
            return;
        }
        show( els.modal, true );
        document.body.classList.add( 'stwg-modal-open' );
        els.closeModal?.focus();
    }

    function closeModal() {
        recordDismissIfUnclaimed();
        show( els.modal, false );
        document.body.classList.remove( 'stwg-modal-open' );
        els.openModal?.focus();
    }

    /* ──────────────────────────────────────────────────────────────────────
       "Did Not Continue" tracking
       Fires once per page load when the user has seen a result (won /
       already_spun) but never clicked the claim button before closing the
       modal or leaving the page.
    ────────────────────────────────────────────────────────────────────── */
    let dismissBeaconSent = false;

    function recordDismissIfUnclaimed() {
        if ( dismissBeaconSent ) { return; }
        const s = STATE.current;
        if ( s !== 'won' && s !== 'already_spun' ) { return; }

        dismissBeaconSent = true;

        const payload = new URLSearchParams( {
            action: 'stwg_dismiss_spin',
            mode:   MODE,
            nonce:  NONCE,
        } );
        if ( MODE === 'order' ) {
            payload.set( 'order_id',  String( ORDER_ID  || '' ) );
            payload.set( 'order_key', String( ORDER_KEY || '' ) );
        }

        try {
            if ( typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function' ) {
                const blob = new Blob( [ payload.toString() ], { type: 'application/x-www-form-urlencoded' } );
                if ( navigator.sendBeacon( AJAX_URL, blob ) ) {
                    return;
                }
            }
            fetch( AJAX_URL, { method: 'POST', body: payload, keepalive: true } ).catch( () => {} );
        } catch ( e ) {
            // Beacon failures must never break the UI.
        }
    }

    window.addEventListener( 'pagehide',         recordDismissIfUnclaimed );
    window.addEventListener( 'beforeunload',     recordDismissIfUnclaimed );
    document.addEventListener( 'visibilitychange', () => {
        if ( document.visibilityState === 'hidden' ) {
            recordDismissIfUnclaimed();
        }
    } );

    function updatePersistentNote( text ) {
        if ( ! els.persistentNote ) { return; }
        if ( text ) {
            els.persistentNote.textContent = text;
            show( els.persistentNote, true );
            show( els.openModal, false );
            show( els.gateNotice, false );
        } else {
            els.persistentNote.textContent = '';
            show( els.persistentNote, false );
            show( els.openModal, true );
            syncCheckoutGate();
        }
    }

    function getCheckoutEmail() {
        const checkoutField = document.getElementById( 'billing_email' );
        return ( checkoutField?.value?.trim() || CHECKOUT_EMAIL || '' );
    }

    function isValidCheckoutEmail( email ) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( email || '' ) );
    }

    /** Classic WooCommerce checkout markup (.validate-required); not for Cart/Checkout blocks. */
    function getBillingFieldsRoot() {
        return document.querySelector( '.woocommerce-billing-fields' )
            || document.querySelector( 'form.checkout .woocommerce-billing-fields__field-wrapper' )
            || document.querySelector( '#customer_details .woocommerce-billing-fields__field-wrapper' );
    }

    function isBillingRowVisible( row ) {
        if ( ! row ) { return false; }
        const cs = window.getComputedStyle( row );
        return cs.display !== 'none' && cs.visibility !== 'hidden';
    }

    function forEachRequiredBillingControl( fn ) {
        const root = getBillingFieldsRoot();
        if ( ! root ) { return; }
        root.querySelectorAll( '.form-row.validate-required' ).forEach( row => {
            if ( ! isBillingRowVisible( row ) ) { return; }
            const control = row.querySelector( 'input[name^="billing_"], select[name^="billing_"], textarea[name^="billing_"]' );
            if ( ! control || control.disabled || control.type === 'hidden' ) { return; }
            fn( row, control );
        } );
    }

    function isBillingSectionComplete() {
        if ( MODE !== 'checkout_test' ) { return true; }
        let anyRequired = false;
        let allFilled = true;
        forEachRequiredBillingControl( ( row, control ) => {
            anyRequired = true;
            if ( ! String( control.value || '' ).trim() ) {
                allFilled = false;
            }
        } );
        if ( ! anyRequired ) { return true; }
        return allFilled;
    }

    function findFirstEmptyRequiredBillingControl() {
        let found = null;
        forEachRequiredBillingControl( ( row, control ) => {
            if ( found ) { return; }
            if ( ! String( control.value || '' ).trim() ) {
                found = control;
            }
        } );
        return found;
    }

    function scrollToBillingDetails() {
        const el = findFirstEmptyRequiredBillingControl();
        if ( el ) {
            focusScrollField( el );
            return;
        }
        const anchor = document.getElementById( 'customer_details' )
            || document.querySelector( '.woocommerce-billing-fields' )
            || getBillingFieldsRoot();
        anchor?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
    }

    function syncCheckoutGate() {
        const email = getCheckoutEmail();
        const isValidEmail = isValidCheckoutEmail( email );
        const billingOk = MODE !== 'checkout_test' || isBillingSectionComplete();
        const gateOk = MODE === 'order'
            ? ( ORDER_ID > 0 && isValidEmail )
            : ( isValidEmail && billingOk );

        const emailBad = MODE !== 'order' && ! isValidEmail;
        const billingBad = MODE === 'checkout_test' && isValidEmail && ! billingOk;

        if ( els.openModal ) {
            if ( MODE === 'checkout_test' ) {
                els.openModal.disabled = false;
            } else {
                els.openModal.disabled = ! gateOk;
            }
        }
        if ( els.spinBtn && STATE.current === 'idle' ) {
            els.spinBtn.disabled = ! gateOk;
        }

        syncGateNotice( emailBad, billingBad, gateOk );

        if ( els.msg && STATE.current === 'idle' ) {
            const modalOpen = isModalOpen();
            if ( MODE === 'checkout_test' && ! modalOpen ) {
                els.msg.textContent = '';
                els.msg.classList.remove( 'is-error', 'is-hint' );
            } else if ( ! gateOk ) {
                if ( emailBad ) {
                    els.msg.textContent = I18N.gate_modal_email || I18N.enter_email || 'Please add your email.';
                    els.msg.classList.remove( 'is-hint' );
                    els.msg.classList.add( 'is-error' );
                } else if ( billingBad ) {
                    els.msg.textContent = I18N.gate_modal_billing || I18N.gate_hint_billing_title || '';
                    els.msg.classList.remove( 'is-error' );
                    els.msg.classList.add( 'is-hint' );
                }
            } else {
                els.msg.textContent = '';
                els.msg.classList.remove( 'is-error', 'is-hint' );
            }
        }
    }

    function syncClaimEmails() {
        const email = getCheckoutEmail();
        const displayEmail = email || '—';
        if ( els.claimEmailNote ) {
            els.claimEmailNote.textContent = displayEmail;
        }
        if ( els.reclaimEmailNote ) {
            els.reclaimEmailNote.textContent = displayEmail;
        }
    }

    if ( ! NUM_SLICES ) {
        if ( els.spinBtn ) { els.spinBtn.disabled = true; }
        if ( els.msg ) {
            els.msg.textContent = I18N.error_generic || 'No gifts are available for this cart.';
            els.msg.classList.add( 'is-error' );
        }
        return;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       BUILD WHEEL (D3)
    ═══════════════════════════════════════════════════════════════════════ */
    const svgRoot = d3.select( '#stwg-wheel' )
        .attr( 'width',  SIZE )
        .attr( 'height', SIZE )
        .attr( 'viewBox', `0 0 ${SIZE} ${SIZE}` );

    // ── Defs: shadow + gradient per slice ──────────────────────────────────
    const defs = svgRoot.append( 'defs' );

    // Outer rim gradient
    const rimGrad = defs.append( 'radialGradient' )
        .attr( 'id', 'stwg-rim-grad' )
        .attr( 'cx', '50%' ).attr( 'cy', '50%' )
        .attr( 'r',  '50%' );
    rimGrad.append( 'stop' ).attr( 'offset', '88%'  ).attr( 'stop-color', 'rgba(255,255,255,0)' );
    rimGrad.append( 'stop' ).attr( 'offset', '100%' ).attr( 'stop-color', 'rgba(255,255,255,0.15)' );

    const shadow = defs.append( 'filter' ).attr( 'id', 'stwg-shadow' );
    shadow.append( 'feDropShadow' )
        .attr( 'dx', 0 ).attr( 'dy', 0 )
        .attr( 'stdDeviation', 10 )
        .attr( 'flood-color', wheelShadowRgba );

    // ── Main rotatable group ───────────────────────────────────────────────
    const wheelGroup = svgRoot.append( 'g' )
        .attr( 'id', 'wheel-group' )
        .attr( 'transform', `translate(${CX},${CY})` )
        .attr( 'filter', 'url(#stwg-shadow)' );

    // ── Arc generator ─────────────────────────────────────────────────────
    const arcGen = d3.arc()
        .innerRadius( INNER_R )
        .outerRadius( OUTER_R );

    const arcGenHighlight = d3.arc()
        .innerRadius( INNER_R )
        .outerRadius( OUTER_R + 8 );

    const pie = d3.pie().value( () => 1 ).sort( null );
    const arcs = pie( GIFTS );

    // ── Slice groups ──────────────────────────────────────────────────────
    const sliceGs = wheelGroup.selectAll( 'g.stwg-slice' )
        .data( arcs )
        .join( 'g' )
        .attr( 'class', 'stwg-slice' )
        .attr( 'data-idx', ( _, i ) => i );

    // Slice paths
    sliceGs.append( 'path' )
        .attr( 'class', 'stwg-slice-path' )
        .attr( 'd', arcGen )
        .attr( 'fill', ( _, i ) => PALETTE[ i % PALETTE.length ] )
        .attr( 'stroke', '#fff' )
        .attr( 'stroke-width', 2 );

    // Overlay for rim shine
    sliceGs.append( 'path' )
        .attr( 'd', arcGen )
        .attr( 'fill', 'url(#stwg-rim-grad)' )
        .style( 'pointer-events', 'none' );

    // Slice labels
    sliceGs.append( 'text' )
        .attr( 'transform', d => {
            const [ x, y ] = arcGen.centroid( d );
            const angleDeg = ( ( d.startAngle + d.endAngle ) / 2 ) * ( 180 / Math.PI ) - 90;
            return `translate(${x},${y}) rotate(${angleDeg})`;
        } )
        .attr( 'text-anchor', 'middle' )
        .attr( 'dominant-baseline', 'middle' )
        .attr( 'fill', '#fff' )
        .attr( 'font-size', NUM_SLICES > 8 ? '11px' : '13px' )
        .attr( 'font-weight', '700' )
        .attr( 'font-family', "'DM Sans', Georgia, sans-serif" )
        .attr( 'paint-order', 'stroke' )
        .attr( 'stroke', 'rgba(0,0,0,.25)' )
        .attr( 'stroke-width', '3px' )
        .style( 'pointer-events', 'none' )
        .each( function( d ) {
            const lines = wrapLabel( d.data.label || 'Gift', NUM_SLICES > 8 ? 12 : 15, 3 );
            const text = d3.select( this );
            const lineHeight = NUM_SLICES > 8 ? 12 : 14;
            lines.forEach( ( line, i ) => {
                text.append( 'tspan' )
                    .attr( 'x', 0 )
                    .attr( 'dy', i === 0 ? `${-( lines.length - 1 ) * lineHeight / 2}px` : `${lineHeight}px` )
                    .text( line );
            } );
        } );

    // ── Outer decorative ring ──────────────────────────────────────────────
    wheelGroup.append( 'circle' )
        .attr( 'r', OUTER_R + 4 )
        .attr( 'fill', 'none' )
        .attr( 'stroke', 'rgba(255,255,255,0.25)' )
        .attr( 'stroke-width', 2 );

    // ── Tick marks ────────────────────────────────────────────────────────
    for ( let t = 0; t < NUM_SLICES; t++ ) {
        const angle = ( t / NUM_SLICES ) * 2 * Math.PI - Math.PI / 2;
        const x1 = Math.cos( angle ) * ( OUTER_R - 2 );
        const y1 = Math.sin( angle ) * ( OUTER_R - 2 );
        const x2 = Math.cos( angle ) * ( OUTER_R + 10 );
        const y2 = Math.sin( angle ) * ( OUTER_R + 10 );
        wheelGroup.append( 'line' )
            .attr( 'x1', x1 ).attr( 'y1', y1 )
            .attr( 'x2', x2 ).attr( 'y2', y2 )
            .attr( 'stroke', stwgAccent )
            .attr( 'stroke-width', 2 )
            .attr( 'stroke-linecap', 'round' );
    }

    // ── Centre hub ────────────────────────────────────────────────────────
    wheelGroup.append( 'circle' )
        .attr( 'r', INNER_R - 2 )
        .attr( 'fill', hubFill )
        .attr( 'stroke', stwgAccent )
        .attr( 'stroke-width', 3 );

    const hubLogoHalf = INNER_R - 6;
    if ( CENTER_LOGO_URL ) {
        wheelGroup.append( 'image' )
            .attr( 'href', CENTER_LOGO_URL )
            .attr( 'x', -hubLogoHalf )
            .attr( 'y', -hubLogoHalf )
            .attr( 'width', hubLogoHalf * 2 )
            .attr( 'height', hubLogoHalf * 2 )
            .attr( 'preserveAspectRatio', 'xMidYMid meet' )
            .style( 'pointer-events', 'none' );
    } else {
        wheelGroup.append( 'text' )
            .attr( 'text-anchor', 'middle' )
            .attr( 'dominant-baseline', 'middle' )
            .attr( 'fill', stwgAccent )
            .attr( 'font-size', `${Math.round( INNER_R * 0.7 )}px` )
            .style( 'pointer-events', 'none' )
            .text( '🎁' );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ROTATION TRACKING
    ═══════════════════════════════════════════════════════════════════════ */
    let cumulativeRotation = 0;   // always increasing – never wraps

    /**
     * Compute the target cumulative rotation (degrees) so that the slice
     * at `index` is centred under the top pointer (12 o'clock = 270° in
     * standard SVG coords, but D3 pie starts at 12 o'clock naturally when
     * we offset by -90° in the text transform above).
     *
     * The pointer lives at the top of the SVG (before any group rotation),
     * which corresponds to angle 0 in the group's local coordinate after
     * our translate. The pie is laid out starting at -π/2 (12 o'clock).
     *
     * Winning slice mid-angle (radians from 12-o'clock, clockwise):
     *   midAngle = (index + 0.5) * SLICE_DEG   (degrees)
     *
     * We need to rotate the wheel by -(midAngle) so the slice centres on
     * the pointer, plus enough full rotations for drama.
     */
    function targetRotation( index ) {
        const midDeg   = ( index + 0.5 ) * SLICE_DEG;

        // We want midDeg to land at 0° (12 o'clock pointer) after rotation.
        // Required group rotation = 360 - midDeg  (clockwise feels natural).
        const stopAngle = ( 360 - midDeg + 360 ) % 360;

        // Bring it beyond current rotation, add extra full spins
        const current360 = ( ( cumulativeRotation % 360 ) + 360 ) % 360;
        const delta      = ( stopAngle - current360 + 360 ) % 360;
        return cumulativeRotation + SPIN_ROTATIONS * 360 + delta;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ANIMATE WHEEL TO INDEX
    ═══════════════════════════════════════════════════════════════════════ */
    function spinToIndex( index, onComplete ) {
        const target = targetRotation( index );

        wheelGroup
            .transition()
            .duration( SPIN_DURATION )
            .ease( EASE_FN )
            .attrTween( 'transform', () => {
                const interp = d3.interpolateNumber( cumulativeRotation, target );
                return t => {
                    const deg = interp( t );
                    return `translate(${CX},${CY}) rotate(${deg})`;
                };
            } )
            .on( 'end', () => {
                cumulativeRotation = target;
                highlightSlice( index );
                if ( typeof onComplete === 'function' ) { onComplete(); }
            } );
    }

    /**
     * Snap wheel to index without animation (used for already-spun state).
     */
    function snapToIndex( index ) {
        const midDeg  = ( index + 0.5 ) * SLICE_DEG;
        const snapDeg = ( 360 - midDeg + 360 ) % 360;
        cumulativeRotation = snapDeg;
        wheelGroup.attr( 'transform', `translate(${CX},${CY}) rotate(${snapDeg})` );
        highlightSlice( index );
    }

    function highlightSlice( index ) {
        // Expand winning slice slightly
        sliceGs.select( '.stwg-slice-path' )
            .transition().duration( 300 )
            .attr( 'd', ( d, i ) => i === index ? arcGenHighlight( d ) : arcGen( d ) );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       RENDER – single place that mutates the DOM based on state
    ═══════════════════════════════════════════════════════════════════════ */
    function render( state ) {
        const s = state.current;

        // Loading overlay
        show( els.loadingOvl, s === 'loading' );

        // Spin button
        if ( els.spinBtn ) {
            els.spinBtn.disabled    = s !== 'idle';
            els.spinBtn.textContent = s === 'loading'
                ? ( I18N.spinning || '⏳ Spinning…' )
                : ( I18N.spin     || '✨ Spin the Wheel' );
        }

        // Result panel
        show( els.result, s === 'won' || s === 'claiming' || s === 'claimed' );
        if ( ( s === 'won' || s === 'claiming' || s === 'claimed' ) && els.prizeLabel ) {
            els.prizeLabel.textContent =
                ( I18N.you_won || '🎁 You won:' ) + ' ' + ( state.giftLabel || '' );
        }
        if ( els.resultNote ) {
            if ( s === 'won' && ! state.canClaim ) {
                els.resultNote.textContent = I18N.message_only || 'No gift claim is needed for this result.';
                show( els.resultNote, true );
            } else if ( s === 'claimed' ) {
                els.resultNote.textContent = ( I18N.added_note || 'Your gift has been added:' ) + ' ' + ( state.giftLabel || '' );
                show( els.resultNote, true );
            } else {
                els.resultNote.textContent = '';
                show( els.resultNote, false );
            }
        }

        // Claim button inside result panel
        if ( els.claimBtn ) {
            els.claimBtn.disabled    = s !== 'won' || ! state.canClaim;
            els.claimBtn.style.display = state.canClaim ? '' : 'none';
            els.claimBtn.textContent = s === 'claiming'
                ? ( I18N.claiming || '⏳ Adding to cart…' )
                : ( I18N.claim    || '🛒 Claim My Gift' );
        }
        show( els.firstOrderConfirm?.closest( '.stwg-first-order-check' ), state.canClaim );

        // Already-spun panel
        show( els.alreadySpun, s === 'already_spun' );
        if ( s === 'already_spun' && els.alreadyMsg ) {
            els.alreadyMsg.textContent =
                ( I18N.already_note || 'You spun the wheel. Your gift is:' ) + ' '
                + ( state.giftLabel || '' );
            updatePersistentNote( els.alreadyMsg.textContent );
        }
        if ( s === 'won' && ! state.canClaim ) {
            updatePersistentNote( ( I18N.already_note || 'You spun the wheel. Your gift is:' ) + ' ' + ( state.giftLabel || '' ) );
        }

        // Reclaim button
        if ( els.reclaimBtn ) {
            els.reclaimBtn.disabled    = s === 'claiming' || s === 'claimed' || ! state.canClaim;
            els.reclaimBtn.style.display = state.canClaim ? '' : 'none';
            els.reclaimBtn.textContent = s === 'claiming'
                ? ( I18N.claiming || '⏳ Adding to cart…' )
                : ( I18N.claim    || '🛒 Claim My Gift' );
        }
        show( els.reclaimFirstOrderConfirm?.closest( '.stwg-first-order-check' ), state.canClaim );

        // Message area
        if ( els.msg ) {
            if ( s === 'error' ) {
                els.msg.textContent = state.errorMsg || ( I18N.error_generic || 'Something went wrong.' );
                els.msg.classList.add( 'is-error' );
            } else if ( s === 'claimed' ) {
                if ( MODE === 'checkout_test' ) {
                    els.msg.textContent = I18N.checkout_gift_added || ( I18N.added_note || 'Your gift has been added:' ) + ' ' + ( state.giftLabel || '' );
                } else {
                    els.msg.textContent = ( I18N.redirect_msg || ( I18N.added_note || 'Your gift has been added:' ) + ' ' + ( state.giftLabel || '' ) );
                }
                updatePersistentNote( ( I18N.added_note || 'Your gift has been added:' ) + ' ' + ( state.giftLabel || '' ) );
                els.msg.classList.remove( 'is-error' );
            } else {
                els.msg.textContent = '';
                els.msg.classList.remove( 'is-error' );
            }
        }

        // Spin button visibility
        if ( els.spinBtn ) {
            els.spinBtn.style.display =
                ( s === 'already_spun' || s === 'won' || s === 'claiming' || s === 'claimed' )
                    ? 'none' : '';
        }

        syncPrizeValueDisplays( state );
    }

    function show( el, visible ) {
        if ( el ) { el.style.display = visible ? '' : 'none'; }
    }

    /** Retail line for product outcomes (price HTML from server). */
    function syncPrizeValueDisplays( state ) {
        const s = state.current;
        const isProduct = ( state.outcomeType || 'product' ) === 'product';
        const html = isProduct && state.giftValueHtml ? String( state.giftValueHtml ) : '';
        const label = I18N.retail_value || 'Retail value:';
        const inner = html ? ( label + ' ' + html ) : '';

        if ( els.prizeValue ) {
            els.prizeValue.innerHTML = inner;
            show( els.prizeValue, Boolean( html ) && ( s === 'won' || s === 'claiming' || s === 'claimed' ) );
        }
        if ( els.alreadyPrizeValue ) {
            els.alreadyPrizeValue.innerHTML = inner;
            show( els.alreadyPrizeValue, Boolean( html ) && s === 'already_spun' );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
       AJAX HELPERS
    ═══════════════════════════════════════════════════════════════════════ */
    function postAjax( action, extra = {} ) {
        const payload = {
            action,
            mode: MODE,
            nonce: NONCE,
            ...extra,
        };

        if ( MODE === 'order' ) {
            payload.order_id = ORDER_ID;
            payload.order_key = ORDER_KEY;
        }

        const body = new URLSearchParams( payload );
        return fetch( AJAX_URL, { method: 'POST', body } )
            .then( async r => {
                let data = null;
                try {
                    data = await r.json();
                } catch ( e ) {
                    data = null;
                }

                if ( ! r.ok ) {
                    const message = data?.data?.message || `HTTP ${r.status}`;
                    const err = new Error( message );
                    err.code = data?.data?.code || `http_${r.status}`;
                    throw err;
                }
                return data;
            } )
            .then( data => {
                if ( ! data.success ) {
                    throw Object.assign(
                        new Error( data.data?.message || 'Server error' ),
                        { code: data.data?.code }
                    );
                }
                return data.data;
            } );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       CONFETTI
    ═══════════════════════════════════════════════════════════════════════ */
    function fireConfetti() {
        if ( typeof confetti !== 'function' ) { return; }
        const count = 180;
        const defaults = { origin: { y: 0.65 }, zIndex: 9999 };
        function fire( particleRatio, opts ) {
            confetti( Object.assign( {}, defaults, opts, {
                particleCount: Math.floor( count * particleRatio ),
            } ) );
        }
        const confettiPalette = [ stwgAccent, stwgAccent2, PALETTE[1] || stwgAccent2, PALETTE[2] || stwgAccent ].filter(
            ( c, i, a ) => a.indexOf( c ) === i
        );
        fire( 0.25, { spread: 26, startVelocity: 55, colors: [ confettiPalette[0], confettiPalette[1] ] } );
        fire( 0.20, { spread: 60, colors: [ confettiPalette[1] || confettiPalette[0], confettiPalette[2] || confettiPalette[0] ] } );
        fire( 0.35, { spread: 100, decay: 0.91, scalar: 0.8 } );
        fire( 0.10, { spread: 120, startVelocity: 25, decay: 0.92, scalar: 1.2 } );
        fire( 0.10, { spread: 120, startVelocity: 45 } );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       SPIN FLOW
    ═══════════════════════════════════════════════════════════════════════ */
    function handleSpin() {
        if ( STATE.current !== 'idle' ) { return; }
        const checkoutEmail = getCheckoutEmail();
        if ( MODE !== 'order' && ! isValidCheckoutEmail( checkoutEmail ) ) {
            if ( els.msg ) {
                els.msg.textContent = I18N.gate_modal_email || I18N.enter_email || 'Please add your email.';
                els.msg.classList.remove( 'is-hint' );
                els.msg.classList.add( 'is-error' );
            }
            return;
        }
        if ( MODE === 'checkout_test' && ! isBillingSectionComplete() ) {
            if ( els.msg ) {
                els.msg.textContent = I18N.gate_modal_billing || I18N.gate_hint_billing_title || '';
                els.msg.classList.remove( 'is-error' );
                els.msg.classList.add( 'is-hint' );
            }
            return;
        }
        STATE.set( 'loading' );

        const spinExtra = MODE === 'order' ? {} : { checkout_email: checkoutEmail };
        postAjax( 'spin_wheel', spinExtra )
            .then( data => {
                if ( data.already_spun ) {
                    // Server says this order already spun
                    const outcomeId = data.outcome_id || outcomeIdForGiftId( data.gift_id );
                    const idx = indexForOutcomeId( outcomeId );
                    if ( idx !== -1 ) { snapToIndex( idx ); }
                    STATE.set( 'already_spun', {
                        outcomeId,
                        giftId:    data.gift_id || 0,
                        giftLabel: data.gift_label || labelForOutcomeId( outcomeId ),
                        outcomeType: data.outcome_type || typeForOutcomeId( outcomeId ),
                        giftValueHtml: data.gift_value_html || '',
                        canClaim: data.can_claim !== false && canClaimForOutcomeId( outcomeId ),
                    } );
                    return;
                }

                const outcomeId = data.outcome_id || outcomeIdForGiftId( data.gift_id );
                const giftId  = data.gift_id || 0;
                const label   = data.gift_label || labelForOutcomeId( outcomeId );
                const idx     = indexForOutcomeId( outcomeId );
                const canClaim = data.can_claim !== false && canClaimForOutcomeId( outcomeId );
                const giftValueHtml = data.gift_value_html || '';
                const outcomeType = data.outcome_type || typeForOutcomeId( outcomeId );

                if ( idx === -1 ) {
                    throw new Error( 'Won gift not found in wheel catalogue.' );
                }

                // Transition to spinning (hide loading overlay, start D3 anim)
                STATE.set( 'spinning', { outcomeId, giftId, giftLabel: label, outcomeType, giftValueHtml, canClaim } );

                spinToIndex( idx, () => {
                    STATE.set( 'won', { outcomeId, giftId, giftLabel: label, outcomeType, giftValueHtml, canClaim } );
                    if ( canClaim ) { fireConfetti(); }
                } );
            } )
            .catch( err => {
                console.error( '[STWG] Spin error:', err );
                STATE.set( 'error', { errorMsg: err.message } );
                // Allow retry after error
                setTimeout( () => {
                    if ( STATE.current === 'error' ) {
                        STATE.set( 'idle' );
                        syncCheckoutGate();
                    }
                }, 4000 );
            } );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       CLAIM FLOW
    ═══════════════════════════════════════════════════════════════════════ */
    function handleClaim() {
        if ( STATE.current !== 'won' && STATE.current !== 'already_spun' ) { return; }
        if ( ! STATE.canClaim ) { return; }
        const inAlreadySpun = STATE.current === 'already_spun';
        const returnState = inAlreadySpun ? 'already_spun' : 'won';
        const confirmEl = inAlreadySpun ? els.reclaimFirstOrderConfirm : els.firstOrderConfirm;
        const claimEmail = MODE === 'checkout_test'
            ? getCheckoutEmail()
            : ( CHECKOUT_EMAIL || getCheckoutEmail() );

        if ( ! claimEmail ) {
            if ( els.msg ) {
                els.msg.textContent = I18N.enter_email || 'Please enter your email address.';
                els.msg.classList.add( 'is-error' );
            }
            return;
        }

        if ( ! confirmEl?.checked ) {
            if ( els.msg ) {
                els.msg.textContent = I18N.confirm_first || 'Please confirm this gift is for first order only.';
                els.msg.classList.add( 'is-error' );
            }
            return;
        }

        STATE.set( 'claiming', {
            outcomeId: STATE.outcomeId,
            giftId: STATE.giftId,
            giftLabel: STATE.giftLabel,
            outcomeType: STATE.outcomeType,
            giftValueHtml: STATE.giftValueHtml,
            canClaim: STATE.canClaim,
        } );

        postAjax( 'claim_gift', { claim_email: claimEmail } )
            .then( data => {
                const giftValueHtml = data.gift_value_html || STATE.giftValueHtml || '';
                STATE.set( 'claimed', {
                    outcomeId: STATE.outcomeId,
                    giftId: STATE.giftId,
                    giftLabel: data.gift_label || STATE.giftLabel,
                    outcomeType: STATE.outcomeType,
                    giftValueHtml,
                    canClaim: STATE.canClaim,
                } );
                if ( MODE === 'checkout_test' ) {
                    if ( window.jQuery ) {
                        window.jQuery( document.body ).trigger( 'update_checkout' );
                    }
                    setTimeout( () => closeModal(), 450 );
                    return;
                }
                setTimeout( () => {
                    window.location.href = data.cart_url;
                }, 1200 );
            } )
            .catch( err => {
                console.error( '[STWG] Claim error:', err );
                // Go back to won/already_spun so user can retry
                STATE.set( returnState, {
                    outcomeId: STATE.outcomeId,
                    giftId:    STATE.giftId,
                    giftLabel: STATE.giftLabel,
                    outcomeType: STATE.outcomeType,
                    giftValueHtml: STATE.giftValueHtml,
                    canClaim:  STATE.canClaim,
                    errorMsg:  err.message,
                } );
                if ( els.msg ) {
                    els.msg.textContent = err.message || ( I18N.error_generic || 'Something went wrong.' );
                    els.msg.classList.add( 'is-error' );
                }
            } );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       EVENT LISTENERS
    ═══════════════════════════════════════════════════════════════════════ */
    els.spinBtn    ?.addEventListener( 'click', handleSpin  );
    els.claimBtn   ?.addEventListener( 'click', handleClaim );
    els.reclaimBtn ?.addEventListener( 'click', handleClaim );
    els.openModal  ?.addEventListener( 'click', openModal );
    els.closeModal ?.addEventListener( 'click', closeModal );
    els.modalBackdrop ?.addEventListener( 'click', closeModal );
    document.addEventListener( 'keydown', event => {
        if ( event.key === 'Escape' && els.modal?.style.display !== 'none' ) {
            closeModal();
        }
    } );
    function isBillingFieldTarget( target ) {
        const n = target && target.getAttribute && target.getAttribute( 'name' );
        return typeof n === 'string' && n.indexOf( 'billing_' ) === 0;
    }
    document.addEventListener( 'input', event => {
        if ( event.target && isBillingFieldTarget( event.target ) ) {
            syncClaimEmails();
            syncCheckoutGate();
        }
    } );
    document.addEventListener( 'change', event => {
        if ( event.target && isBillingFieldTarget( event.target ) ) {
            syncClaimEmails();
            syncCheckoutGate();
        }
    } );
    els.gateNoticeScroll?.addEventListener( 'click', scrollToCheckoutIssue );
    if ( window.jQuery ) {
        window.jQuery( document.body ).on( 'updated_checkout', () => {
            syncClaimEmails();
            syncCheckoutGate();
        } );
    }

    /* ═══════════════════════════════════════════════════════════════════════
       INITIAL STATE
    ═══════════════════════════════════════════════════════════════════════ */
    if ( cfg.already_spun && ( cfg.won_outcome_id || cfg.won_gift_id ) ) {
        const outcomeId = cfg.won_outcome_id || outcomeIdForGiftId( cfg.won_gift_id );
        const idx = indexForOutcomeId( outcomeId );
        if ( idx !== -1 ) { snapToIndex( idx ); }
        STATE.set( 'already_spun', {
            outcomeId,
            giftId:    cfg.won_gift_id || 0,
            giftLabel: cfg.won_gift_name || labelForOutcomeId( outcomeId ),
            outcomeType: cfg.won_type || typeForOutcomeId( outcomeId ),
            giftValueHtml: cfg.won_value_html || '',
            canClaim: cfg.won_can_claim !== false && canClaimForOutcomeId( outcomeId ),
        } );
    } else {
        STATE.set( 'idle' );
    }

    syncClaimEmails();
    syncCheckoutGate();

    /* ═══════════════════════════════════════════════════════════════════════
       UTILITIES
    ═══════════════════════════════════════════════════════════════════════ */
    function indexForOutcomeId( outcomeId ) {
        return GIFTS.findIndex( g => g.outcome_id === outcomeId );
    }

    function labelForOutcomeId( outcomeId ) {
        const g = GIFTS.find( g => g.outcome_id === outcomeId );
        return g ? g.label : 'Gift';
    }

    function outcomeIdForGiftId( giftId ) {
        const g = GIFTS.find( g => g.product_id === giftId );
        return g ? g.outcome_id : `product_${giftId}`;
    }

    function typeForOutcomeId( outcomeId ) {
        const g = GIFTS.find( g => g.outcome_id === outcomeId );
        return g ? ( g.type || 'product' ) : 'product';
    }

    function canClaimForOutcomeId( outcomeId ) {
        const g = GIFTS.find( g => g.outcome_id === outcomeId );
        return g ? g.can_claim !== false : true;
    }

    function parsePalette( value ) {
        if ( typeof value !== 'string' ) { return null; }
        const colors = value.split( ',' )
            .map( c => c.trim() )
            .filter( c => /^#[0-9a-f]{6}$/i.test( c ) );
        return colors.length ? colors : null;
    }

    function wrapLabel( label, maxChars, maxLines ) {
        const words = String( label ).split( /\s+/ ).filter( Boolean );
        const lines = [];
        let current = '';

        words.forEach( word => {
            if ( ( current + ' ' + word ).trim().length <= maxChars ) {
                current = ( current + ' ' + word ).trim();
            } else {
                if ( current ) { lines.push( current ); }
                current = word.length > maxChars ? word.slice( 0, maxChars - 1 ) + '…' : word;
            }
        } );
        if ( current ) { lines.push( current ); }

        if ( lines.length > maxLines ) {
            lines.length = maxLines;
            lines[ maxLines - 1 ] = lines[ maxLines - 1 ].replace( /…?$/, '…' );
        }
        return lines.length ? lines : [ 'Gift' ];
    }

} )( window.d3, window.confetti, window.spinData );
