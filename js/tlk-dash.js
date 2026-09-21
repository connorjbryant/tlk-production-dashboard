jQuery(document).ready(function ($) {
    if (!$('.dash-container').length || !$('body').hasClass('tlk-prod-dash')) {
        return;
    }

    function forceDesktopViewport() {
        var metas = document.querySelectorAll('meta[name="viewport"]');
        if (!metas.length) {
            var meta = document.createElement('meta');
            meta.setAttribute('name', 'viewport');
            meta.setAttribute('content', 'width=1920, initial-scale=1');
            document.head.appendChild(meta);
        } else {
            metas.forEach(function (meta) {
                meta.setAttribute('content', 'width=1920, initial-scale=1');
            });
        }
    }

    function blowOpenThemeWrappers() {
        document.documentElement.classList.add('tlk-prod-dash');
        document.body.classList.add('tlk-prod-dash');

        var nodes = document.querySelectorAll(
            'body.tlk-prod-dash, body.tlk-prod-dash .site, body.tlk-prod-dash #page, body.tlk-prod-dash #wrapper, body.tlk-prod-dash .site-content, body.tlk-prod-dash #content, body.tlk-prod-dash #primary, body.tlk-prod-dash .content-area, body.tlk-prod-dash .fusion-row, body.tlk-prod-dash .container, body.tlk-prod-dash .wrap, body.tlk-prod-dash main, body.tlk-prod-dash .dash-container'
        );

        nodes.forEach(function (el) {
            el.style.setProperty('width', '100%', 'important');
            el.style.setProperty('max-width', 'none', 'important');
            el.style.setProperty('min-width', '0', 'important');
            el.style.setProperty('float', 'none', 'important');
            el.style.setProperty('margin-left', '0', 'important');
            el.style.setProperty('margin-right', '0', 'important');
        });
    }

    forceDesktopViewport();
    blowOpenThemeWrappers();
    $(window).on('resize orientationchange', blowOpenThemeWrappers);

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