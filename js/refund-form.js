"use strict";
// Generated JavaScript: js/refund-form.js. Edit this TypeScript source and run npm run build.
(function (Drupal, once) {
    'use strict';
    function decimalParts(value) {
        const parts = value.split('.');
        return {
            digits: BigInt((parts[0] || '0') + (parts[1] || '')),
            scale: (parts[1] || '').length,
        };
    }
    function multiply(left, right) {
        const first = decimalParts(left);
        const second = decimalParts(right);
        return {
            digits: first.digits * second.digits,
            scale: first.scale + second.scale,
        };
    }
    function add(left, right) {
        const scale = Math.max(left.scale, right.scale);
        return {
            digits: left.digits * (10n ** BigInt(scale - left.scale))
                + right.digits * (10n ** BigInt(scale - right.scale)),
            scale,
        };
    }
    function format(value) {
        let digits = value.digits.toString();
        if (value.scale === 0) {
            return digits;
        }
        digits = digits.padStart(value.scale + 1, '0');
        const whole = digits.slice(0, -value.scale);
        const fraction = digits.slice(-value.scale);
        let end = fraction.length;
        while (end > 0 && fraction[end - 1] === '0') {
            end -= 1;
        }
        const decimals = fraction.slice(0, end);
        return decimals === '' ? whole : `${whole}.${decimals}`;
    }
    function updateTotals(table, total) {
        let sum = { digits: 0n, scale: 0 };
        let empty = true;
        table.querySelectorAll('.commerce-novapay-refund-line-total').forEach((input) => {
            const raw = input.value.trim();
            empty = empty && (raw === '' || (/^\d+(?:\.\d+)?$/.test(raw) && decimalParts(raw).digits === 0n));
            if (/^\d+(?:\.\d+)?$/.test(raw)) {
                sum = add(sum, decimalParts(raw));
            }
        });
        table.querySelectorAll('.commerce-novapay-refund-quantity').forEach((input) => {
            const raw = input.value.trim();
            empty = empty && (raw === '' || (/^\d+(?:\.\d+)?$/.test(raw) && decimalParts(raw).digits === 0n));
        });
        if (total) {
            total.textContent = `${empty ? table.dataset.refundBalance : format(sum)} ${table.dataset.currency || ''}`;
        }
    }
    Drupal.behaviors.commerceNovaPayRefund = {
        attach(context) {
            once('commerce-novapay-refund', '.commerce-novapay-refund-items', context)
                .forEach((table) => {
                if (!(table instanceof HTMLElement)) {
                    return;
                }
                const total = table.closest('form')?.querySelector('.commerce-novapay-refund-total') || null;
                const update = () => updateTotals(table, total);
                table.querySelectorAll('.commerce-novapay-refund-quantity').forEach((quantity) => {
                    quantity.addEventListener('input', () => {
                        const amount = quantity.closest('tr')?.querySelector('.commerce-novapay-refund-line-total');
                        if (amount) {
                            const raw = quantity.value.trim();
                            amount.value = /^\d+(?:\.\d+)?$/.test(raw)
                                ? format(multiply(quantity.dataset.unitPrice || '0', raw)) : '';
                        }
                        update();
                    });
                });
                table.querySelectorAll('.commerce-novapay-refund-line-total').forEach((amount) => {
                    amount.addEventListener('input', update);
                });
                update();
            });
        },
    };
})(Drupal, once);
