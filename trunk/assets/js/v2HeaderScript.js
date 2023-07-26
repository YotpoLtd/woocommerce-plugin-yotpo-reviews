(function e(){
  var e = document.createElement("script");
  e.type = "text/javascript",e.async = true,e.src = "//staticw2.yotpo.com/" + yotpo_settings.app_key + "/widget.js";
  var t = document.getElementsByTagName("script")[0];
  t.parentNode.insertBefore(e,t)
})();

jQuery(document).ready(() => {
  const yotpoWidgetTab = jQuery('li.yotpo_main_widget_tab>a');
  jQuery('div.bottomLine').click(() => {
    if (yotpoWidgetTab.length) {
      yotpoWidgetTab.click();
    }
  });
  jQuery('div.QABottomLine').click(() => {
    if (yotpoWidgetTab.length) {
      yotpoWidgetTab.click();
      jQuery('li[data-type="questions"]').click();
      jQuery('html, body').animate({
        scrollTop: jQuery(".yotpo-main-widget").offset().top
      }, 1000);
    }
  });
});
