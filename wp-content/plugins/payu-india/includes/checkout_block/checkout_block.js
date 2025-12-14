const settings = window.wc.wcSettings.getSetting('payu_data', {});
//console.log(settings);
const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('PayU CommercePro Plugin', 'payu');
//console.log(label);

const Content = () => {
    return window.wp.htmlEntities.decodeEntities(settings.description || '');
};

const Block_Gateway = {
    name: 'payubiz',
    label: label,
    content: Object(window.wp.element.createElement)(Content, null ),
    edit: Object(window.wp.element.createElement)(Content, null ),
    //canMakePayment: () => true,
    canMakePayment: () => {
        // Ensure that the payment method is available in CommercePro mode as well
        console.log('Checking canMakePayment for PayU');
        return true;  // Always return true to test
    },
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};  
// console.log("====== Block Gateway ==============");
// console.log(Block_Gateway);
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );
 