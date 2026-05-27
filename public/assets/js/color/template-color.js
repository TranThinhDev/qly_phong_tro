new WOW().init();
(function($) {
    "use strict";
    var cp3 = document.getElementById("ColorPicker3");
    if (cp3) {
        var color_picker3 = cp3.value;
        cp3.onchange = function() {
            color_picker3 = this.value;
            document.documentElement.style.setProperty('--theme-default3', color_picker3);
        };
    }

    var cp4 = document.getElementById("ColorPicker4");
    if (cp4) {
        var color_picker4 = cp4.value;
        cp4.onchange = function() {
            color_picker4 = this.value;
            document.documentElement.style.setProperty('--theme-default4', color_picker4);
        };
    }
})(jQuery);