document.addEventListener('DOMContentLoaded', function () {
    // Live wallet balance refresh on Dashboard
    const refreshBtn = document.getElementById('refreshBalanceBtn');
    const availableEl = document.getElementById('liveAvailableBalance');
    const reservedEl = document.getElementById('liveReservedBalance');

    if (refreshBtn && availableEl && reservedEl && refreshBtn.dataset.balanceUrl) {
        refreshBtn.addEventListener('click', async function () {
            refreshBtn.disabled = true;
            try {
                const res = await fetch(refreshBtn.dataset.balanceUrl, {
                    headers: { Accept: 'application/json' }
                });
                if (res.ok) {
                    const data = await res.json();
                    availableEl.textContent = data.formatted_balance;
                    reservedEl.textContent = data.formatted_reserved_balance;
                }
            } finally {
                refreshBtn.disabled = false;
            }
        });
    }

    // Uppercase & alphanumeric filter for Indian IFSC code input
    const ifscInput = document.getElementById('ifsc_code');
    if (ifscInput) {
        ifscInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11);
        });
    }

    // Withdraw Max shortcut & double-submit guard
    const maxBtn = document.getElementById('withdrawMaxBtn');
    const withdrawAmountInput = document.getElementById('withdraw_amount');
    if (maxBtn && withdrawAmountInput) {
        maxBtn.addEventListener('click', function () {
            withdrawAmountInput.value = this.dataset.max;
            withdrawAmountInput.focus();
        });
    }

    const withdrawalForm = document.getElementById('withdrawalForm');
    const withdrawSubmitBtn = document.getElementById('withdrawSubmitBtn');
    if (withdrawalForm && withdrawSubmitBtn) {
        withdrawalForm.addEventListener('submit', function () {
            withdrawSubmitBtn.disabled = true;
            withdrawSubmitBtn.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
        });
    }
});
