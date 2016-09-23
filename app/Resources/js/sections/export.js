var Export = {
    init: function () {
        DCPDF.resetGUI();

        this.bindEvents();
    },

    bindEvents: function () {
        $('.export_input.-page-start, .export_input.-page-end').change(this.checkPageRange.bind(this));

        $('.export_generate-pdf').click(function () {
            var ppn = $(this).data('ppn');
            // TODO: Sanitize. Add option to select arbitrary pages ("1, 3-6, 15").
            var physIDstart = parseInt($('#physIDstart').val());
            var physIDend = parseInt($('#physIDend').val());
            DCPDF.generatePDF(ppn, physIDstart, physIDend);
        });

        $('.export_reset').click(function () {
            DCPDF.resetGUI();
        });
    },

    checkPageRange: function () {
        var $pageStart = $('.export_input.-page-start');
        var $pageEnd = $('.export_input.-page-end');
        var start = parseInt($pageStart.val());
        var end = parseInt($pageEnd.val());
        var max = $('.export_page-count').text();
        var isError = start > end || start > max || end > max;
        $.merge($pageStart, $pageEnd).toggleClass('-error', isError);
        $('.export_error.-page-range').toggle(isError);
        $('.export_generate-pdf').prop('disabled', isError);
    },
}

Export.init();
