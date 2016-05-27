(function ($) {

    var payURL = payAppObj.payURL;
    var payAppWin = null;
    var payAppPolling = null;
    var retryMax = 100;
    var totalPollCount = 0;

    function pollWhileUnpaid() {
        if (++totalPollCount > retryMax) {
            clearInterval(payAppPolling);
            location.href = payAppObj.redirectURL;
            return;
        }
        bb.post("client/invoice/get", {hash: payAppObj.hash}, function (result) {
            switch (result.status) {
                case 'paid':
                    location.href = payAppObj.thankYouUrl;
                    break;
                case 'unpaid':
                    console.log('Unpaid: ' + totalPollCount);
                    return;
                default:
                    location.href = payAppObj.redirectURL;
                    break;
            }
        });
    }

    function onClickPayAppPayment() {
        if (!payAppWin && payURL) {
            payAppWin = window.open(payURL);
        }
        payAppWin.blur();
        payAppPolling = setInterval(pollWhileUnpaid, 5000);
        return false;
    }

    $(document).ready(function () {
        // override loading screen
        $('.loading').unbind('ajaxStart').unbind('ajaxStop');
        $('#payapp-payment').click(onClickPayAppPayment);
    });

})($);