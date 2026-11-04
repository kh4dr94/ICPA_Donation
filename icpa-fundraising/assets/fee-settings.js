(function () {
    'use strict';
    const descriptions = {
        absorb: 'ICPA absorbs processing costs. The donor pays only their chosen donation amount.',
        pass_on: 'The configured estimated processing cost is added to the donation. The full total is shown before payment.',
        customer_choice: 'The donor chooses donation only or adds an estimated contribution toward processing costs. Donation only is selected by default.'
    };
    function update(picker) {
        const selected = picker.querySelector('input:checked');
        if (selected) picker.querySelector('[data-fee-mode-description]').textContent = descriptions[selected.value];
    }
    document.querySelectorAll('.icpa-fee-mode-picker').forEach(update);
    document.addEventListener('change', event => {
        const picker = event.target.closest('.icpa-fee-mode-picker');
        if (picker) update(picker);
    });
}());
