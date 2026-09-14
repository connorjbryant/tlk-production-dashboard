jQuery(document).ready(function ($) {

    var summaryTitle = $('.dashboard-card__details');
    var summaryContent = $('.dashboard-card__details-content');
    var toggleMsg = $('.toggle-msg');

    $('#employee').on('change', function () {

        if ($(this).val() === '__new__') {

            $('#new-employee-wrap').show();

            $('#new_employee')
                .prop('required', true)
                .focus();

        } else {

            $('#new-employee-wrap').hide();

            $('#new_employee')
                .prop('required', false)
                .val('');
        }

    });

    summaryTitle.on('toggle', function(){
        if (this.open){
            summaryTitle.addClass("active-summary");
        } else {
            summaryTitle.removeClass("active-summary");
        }
    });

});