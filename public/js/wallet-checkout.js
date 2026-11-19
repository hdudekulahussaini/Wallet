document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('addMoneyForm');
    if (!form) {
        return;
    }

    const amountInput = document.getElementById('amount');
    const phoneInput = document.getElementById('phone');
    const payBtn = document.getElementById('payNowBtn');
    const payBtnSpinner = document.getElementById('payBtnSpinner');
    const feedback = document.getElementById('paymentFeedback');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const orderUrl = form.dataset.orderUrl;
    const checkoutMode = form.dataset.checkoutMode || 'sandbox';

    document.querySelectorAll('.amount-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            amountInput.value = Number(this.dataset.amount).toFixed(2);
            amountInput.focus();
        });
    });

    function showFeedback(type, message) {
        feedback.className = 'alert alert-' + type;
        feedback.textContent = message;
        feedback.classList.remove('d-none');
    }

    function setLoading(loading) {
        payBtn.disabled = loading;
        payBtnSpinner.classList.toggle('d-none', !loading);
    }

    async function verifyOrderOnServer(verifyUrl) {
        showFeedback('info', 'Confirming payment status securely with Cashfree server...');
        const verifyRes = await fetch(verifyUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken
            }
        });
        const verifyData = await verifyRes.json();

        if (verifyData.status === 'completed') {
            showFeedback('success', verifyData.message + ' Refreshing dashboard...');
            setTimeout(() => window.location.reload(), 1200);
        } else if (verifyData.status === 'failed') {
            showFeedback('danger', verifyData.message || 'Payment failed.');
            setTimeout(() => window.location.reload(), 1800);
        } else {
            showFeedback('warning', verifyData.message || 'Payment pending confirmation. Reloading...');
            setTimeout(() => window.location.reload(), 1800);
        }
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        feedback.classList.add('d-none');

        const amountVal = amountInput.value.trim();
        const phoneVal = phoneInput.value.trim();

        if (!amountVal || Number(amountVal) <= 0) {
            showFeedback('danger', 'Please enter a valid deposit amount.');
            return;
        }

        if (phoneVal && !/^\d{10}$/.test(phoneVal)) {
            showFeedback('danger', 'Please enter a valid 10-digit mobile number.');
            return;
        }

        setLoading(true);

        try {
            const response = await fetch(orderUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    amount: amountVal,
                    phone: phoneVal || null
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                const msg =
                    data.message ||
                    (data.errors ? Object.values(data.errors).flat().join(' ') : 'Unable to create Cashfree order.');
                showFeedback('danger', msg);
                setLoading(false);
                return;
            }

            if (typeof Cashfree !== 'function') {
                showFeedback('danger', 'Cashfree JavaScript SDK failed to load. Check your internet connection.');
                setLoading(false);
                return;
            }

            const cashfree = Cashfree({ mode: data.checkout_mode || checkoutMode });
            const result = await cashfree.checkout({
                paymentSessionId: data.payment_session_id,
                redirectTarget: '_modal'
            });

            if (result && result.error) {
                await verifyOrderOnServer(data.verify_url);
                return;
            }

            // Always confirm payment server-side via Cashfree API; never credit from frontend callback
            await verifyOrderOnServer(data.verify_url);
        } catch (err) {
            showFeedback('danger', 'Unexpected error while initiating payment. Please try again.');
        } finally {
            setLoading(false);
        }
    });
});
