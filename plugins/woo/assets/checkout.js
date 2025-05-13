const Pay4_settings = window.wc.wcSettings.getSetting( 'WC_Payment4_data', {} );
const Pay4_label = window.wp.htmlEntities.decodeEntities( Pay4_settings.title ) || window.wp.i18n.__( '( Pay with Crypto )', 'payment4-woocommerce' );
const Pay4_Content = () => {
    return window.wp.htmlEntities.decodeEntities( Pay4_settings.description || window.wp.i18n.__('Accepting Crypto Payments', 'payment4-woocommerce') );
};

const Pay4_Icon = () => {
    return Pay4_settings.icon
        ? React.createElement('img', { src: Pay4_settings.icon })
        : null;
}

const Pay4_Label = () => {
    return React.createElement(
        'span',
        { style: { width: '97%', display: 'flex', justifyContent: 'space-between' } },
        Pay4_label,
        React.createElement(Pay4_Icon)
    );
}

const Pay4_Block_Gateway = {
    name: 'WC_Payment4',
    label: React.createElement(Pay4_Label),
    content: React.createElement(Pay4_Content),
    edit: React.createElement(Pay4_Content),
    canMakePayment: () => true,
    ariaLabel: Pay4_label,
    supports: {
        features: Pay4_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Pay4_Block_Gateway );
console.log(Pay4_Block_Gateway)