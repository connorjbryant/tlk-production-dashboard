jQuery(document).ready(function ($) {

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

});