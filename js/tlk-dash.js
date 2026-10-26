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

    /*
     * TV display helpers.
     * - Refresh an unattended dashboard every 5 minutes so production data stays current.
     * - Nudge the rendered dashboard by 1px once per minute to keep a long-running TV
     *   display from being perfectly static and to help reduce image retention.
     *
     * A refresh is deferred while someone is actively editing a form so an in-progress
     * production entry is never discarded by the TV refresh timer.
     */
    var dashboardDirty = false;
    var refreshEveryMs = 5 * 60 * 1000;
    var refreshRetryMs = 60 * 1000;
    var pixelShiftEveryMs = 60 * 1000;
    var pixelShiftIndex = 0;
    var pixelShifts = [
        [0, 0], [1, 0], [1, 1], [0, 1],
        [-1, 1], [-1, 0], [-1, -1], [0, -1]
    ];

    $(document).on('input change', '.dash-container form :input', function () {
        dashboardDirty = true;
    });

    $('.dash-container form').on('submit', function () {
        dashboardDirty = false;
    });

    function dashboardIsBeingEdited() {
        var active = document.activeElement;
        var activeInForm = active && $(active).closest('.dash-container form').length > 0;
        return dashboardDirty || activeInForm;
    }

    function refreshDashboardWhenIdle() {
        if (document.visibilityState === 'visible' && !dashboardIsBeingEdited()) {
            window.location.reload();
            return;
        }

        window.setTimeout(refreshDashboardWhenIdle, refreshRetryMs);
    }

    function shiftDashboardPixels() {
        pixelShiftIndex = (pixelShiftIndex + 1) % pixelShifts.length;
        var shift = pixelShifts[pixelShiftIndex];
        var $dashboard = $('.dash-container');

        $dashboard.css('transform', 'translate3d(' + shift[0] + 'px,' + shift[1] + 'px,0)');
        $dashboard.css('background-position', 'calc(50% + ' + shift[0] + 'px) calc(50% + ' + shift[1] + 'px)');
    }

    /*
     * Screen Wake Lock API.
     * Supported browsers can ask the device to keep the display awake while this
     * dashboard is visible. Browsers may release the lock when the page is hidden,
     * so request it again when the dashboard becomes visible. Unsupported browsers
     * simply continue using the refresh/pixel-shift helpers above.
     */
    var dashboardWakeLock = null;

    async function requestDashboardWakeLock() {
        if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') {
            return;
        }

        try {
            dashboardWakeLock = await navigator.wakeLock.request('screen');
            dashboardWakeLock.addEventListener('release', function () {
                dashboardWakeLock = null;
            });
        } catch (error) {
            // Wake Lock can be unavailable/denied without affecting the dashboard.
            dashboardWakeLock = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !dashboardWakeLock) {
            requestDashboardWakeLock();
        }
    });

    requestDashboardWakeLock();

    window.setTimeout(refreshDashboardWhenIdle, refreshEveryMs);
    window.setInterval(shiftDashboardPixels, pixelShiftEveryMs);

    var settingsNode = document.getElementById('tlk-entry-settings');
    var entrySettings = settingsNode ? JSON.parse(settingsNode.textContent) : {enabled:{},parts:{}};
    var entryMode = 'basic';
    function updateNotesVisibility() {
        var enabled = !!((entrySettings.notes || {})[$('#department').val()]);
        $('#production-entry-list .tlk-entry-notes').each(function(){
            $(this).prop('hidden', !enabled).find('textarea').prop('disabled', !enabled);
        });
    }
    $('#department').on('change', updateNotesVisibility);
    $(document).on('click', '#production-add-row', function(){ window.setTimeout(updateNotesVisibility, 0); });
    $(document).on('change', '.recent-entry__form select[name=department]', function(){
        var $form = $(this).closest('form'), $field = $form.find('.tlk-edit-notes');
        var enabled = !!((entrySettings.notes || {})[$(this).val()]);
        $field.prop('hidden', !enabled).find('textarea').prop('disabled', !enabled);
    });
    updateNotesVisibility();
    function addPart($row) {
        var dept = $('#department').val();
        var index = $('#production-entry-list .production-entry-row').index($row);
        var parts = (entrySettings.parts || {})[dept] || [];
        var $line = $('<div class="tlk-part-line"><input type="text" class="tlk-part-name" placeholder="Part 1, 2, etc." required><input type="number" class="tlk-part-qty" min="0" placeholder="0" required><button type="button" class="tlk-remove-part" aria-label="Remove part" data-tooltip="Remove last part">×</button></div>');
        $line.find('.tlk-part-name').attr('name','part_name['+index+'][]').attr('list','tlk-part-options');
        $line.find('.tlk-part-qty').attr('name','part_qty['+index+'][]');
        $row.find('.tlk-part-lines').append($line);
    }
    function updateEntryMode() {
        var dept = $('#department').val();
        var allowed = !!(entrySettings.enabled || {})[dept];
        if (!allowed) entryMode = 'basic';
        $('#tlk-mode-switch').toggle(allowed);
        $('#tlk-entry-mode').val(entryMode);
        $('#tlk-mode-switch button').removeClass('active').filter('[data-mode="'+entryMode+'"]').addClass('active');
        $('#production-entry-list .production-entry-row').each(function () {
            var $row = $(this), advanced = entryMode === 'advanced';
            $row.find('.tlk-advanced-parts').prop('hidden', !advanced);
            $row.find('input[name="qty[]"]').prop('disabled', false).prop('required', !advanced).val(advanced ? '0' : '').closest('.production-entry-field').toggle(!advanced);
            if (advanced && !$row.find('.tlk-part-line').length) addPart($row);
            $row.find('.tlk-part-name,.tlk-part-qty').prop('disabled', !advanced);
            $row.find('.tlk-part-line').each(function(){
                var idx = $('#production-entry-list .production-entry-row').index($row);
                $(this).find('.tlk-part-name').attr('name','part_name['+idx+'][]');
                $(this).find('.tlk-part-qty').attr('name','part_qty['+idx+'][]');
            });
        });
        $('#tlk-part-options').remove();
        var $list = $('<datalist id="tlk-part-options"></datalist>');
        ((entrySettings.parts || {})[dept] || []).forEach(function(part){$list.append($('<option>').val(part));});
        $('body').append($list);
    }
    $('#tlk-mode-switch button').on('click', function(){entryMode=$(this).data('mode');updateEntryMode();});
    $(document).on('click','.tlk-add-part',function(){addPart($(this).closest('.production-entry-row'));});
    $(document).on('click','.tlk-remove-part',function(){var $row=$(this).closest('.production-entry-row');if($row.find('.tlk-part-line').length>1)$(this).closest('.tlk-part-line').remove();});
    // Default to Advanced whenever the selected department permits it.
    // Employees may still manually switch back to Basic for that department.
    function defaultDepartmentEntryMode() {
        var dept = $('#department').val();
        entryMode = (entrySettings.enabled || {})[dept] ? 'advanced' : 'basic';
        updateEntryMode();
    }
    $('#department').on('change', defaultDepartmentEntryMode);
    defaultDepartmentEntryMode();
    var $grid = $('.tlk-production-grid');
    var $cards = $grid.children('.tlk-production-card');
    var $dashboard = $('.dash-container');
    var imageBase = $dashboard.attr('data-tlk-image-base') || '';
    var monthBackground = $dashboard.attr('data-tlk-month-image') || '';
    // Read the image URL directly from each rendered card to avoid mismatches
    // between department names, CSS classes, and image filenames.
    function updateDepartmentBackground($card) {
        if (!$dashboard.length || !$card.length) return;
        var departmentUrl = $card.attr('data-tlk-background') || '';
        var useMonth = !!monthBackground && Math.random() < 0.20;
        var url = useMonth ? monthBackground : departmentUrl;
        if (url) {
            $dashboard[0].style.setProperty('background-image', 'url("' + url + '")', 'important');
        }
    }

    if ($cards.length) {
        var activeCard = 0;
        function rotateCards() {
            $cards.removeClass('tlk-card-active').attr('aria-hidden', 'true');
            var $active = $cards.eq(activeCard);
            $active.addClass('tlk-card-active').attr('aria-hidden', 'false');
            updateDepartmentBackground($active);
        }
        rotateCards();
        $grid.addClass('tlk-rotation-ready');
        if ($cards.length > 1) {
            window.setInterval(function () {
                activeCard = (activeCard + 1) % $cards.length;
                rotateCards();
            }, 12000);
        }
    }
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
        updateEntryMode();
    });

    $(document).on('click', '.production-remove-row', function () {
        var $rows = $('#production-entry-list .production-entry-row');

        if ($rows.length <= 1) {
            return;
        }

        $(this).closest('.production-entry-row').remove();
        updateRemoveButtons();
        updateEntryMode();
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
        'Machining': 'machining.jpg',
        'Pouring': 'pouring.jpg',
        
        'Assembly & Packaging': 'assembly_packaging.jpg',
        'Flex Rock': 'flex_rock.jpg'
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