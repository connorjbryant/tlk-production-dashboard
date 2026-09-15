jQuery(document).ready(function ($) {
    var summaryTitle = $('.dashboard-card__details');

    function updateRemoveButtons() {
        var rows = $('#production-entry-list .production-entry-row');
        rows.find('.production-remove-row').prop('disabled', rows.length === 1);
    }

    function handleEmployeeChange(select) {
        var $select = $(select);
        var $row = $select.closest('.production-entry-row');
        var $newEmployee = $row.find('.production-new-employee');

        if ($select.val() === '__new__') {
            $newEmployee.show().prop('required', true).focus();
        } else {
            $newEmployee.hide().prop('required', false).val('');
        }
    }

    $(document).on('change', '.production-employee', function () {
        handleEmployeeChange(this);
    });

    $('#production-add-row').on('click', function () {
        var template = document.getElementById('production-entry-template');

        if (!template) {
            return;
        }

        $('#production-entry-list').append(template.content.cloneNode(true));
        updateRemoveButtons();
    });

    $(document).on('click', '.production-remove-row', function () {
        var $rows = $('#production-entry-list .production-entry-row');

        if ($rows.length <= 1) {
            return;
        }

        $(this).closest('.production-entry-row').remove();
        updateRemoveButtons();
    });

    updateRemoveButtons();

    summaryTitle.on('toggle', function () {
        if (this.open) {
            $(this).addClass('active-summary');
        } else {
            $(this).removeClass('active-summary');
        }
    });
});