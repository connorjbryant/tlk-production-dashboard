jQuery(document).ready(function ($) {
    function flagLargeDisplay() {
        var ua = navigator.userAgent || '';
        var isTv = /Tizen|Web0S|WebOS|SmartTV|SMART-TV|SmartHub|SamsungBrowser\/[.0-9]+.*TV|HbbTV|NetCast|Viera|AFT|AppleTV|GoogleTV|BRAVIA/i.test(ua);
        var wide = Math.max(
            screen.width || 0,
            screen.height || 0,
            window.innerWidth || 0,
            window.innerHeight || 0
        ) >= 900;

        if (isTv || wide) {
            document.documentElement.classList.add('tlk-large-display');
            document.body.classList.add('tlk-large-display');
        }
    }

    flagLargeDisplay();

    var $departmentSelect = $('#department');
    var $departmentImage = $('#department-image');

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

    // Development helper for testing monthly backgrounds.
    window.testMonth = function (month) {
        $('.dash-container').css(
            'background-image',
            'url("/wp-content/plugins/tlk-production-dashboard/images/' + month + '.jpg")'
        );
    };

    if (!$departmentSelect.length || !$departmentImage.length) {
        return;
    }

    var departmentImages = {
        'CNC': 'cnc.jpg',
        'Pouring': 'pouring.jpg',
        'Building': 'building.jpg'
    };

    function updateDepartmentImage() {
        var department = $departmentSelect.val();
        var image = departmentImages[department];

        if (!image) {
            return;
        }

        var imageBase = $departmentImage.data('image-base');

        $departmentImage
            .attr('src', imageBase + image)
            .attr('alt', department);
    }

    $departmentSelect.on('change', function () {
        updateDepartmentImage();
    });

    updateDepartmentImage();
});