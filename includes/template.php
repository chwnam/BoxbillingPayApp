<?php
/**
 * @var string $pay_url
 * @var string $hash
 * @var string $thankyou_url
 * @var string $redirect_url
 * @var string $directory
 */
?>
<div style="margin-top: 5px;">
    <div style="text-align: right;">
        <a href="#" class="bb-button bb-button-submit" id="payapp-payment">클릭하여 페이앱 결제 진행</a>
    </div>
    <div style="margin-top: 10px;">
        <p>
            위의 버튼(혹은 링크)를 클랙하면 아래 그림과 같은 새 창이 열립니다. 이 창에서 결제를 진행하시면 됩니다.
        </p>
        <p>
            <img src="/bb-library/Payment/Adapter/<?php echo $directory; ?>/includes/payapp-screenshot.png"/>
        </p>
    </div>

</div>
<script type="text/javascript">
    (function ($) {

        var payURL = '<?php echo $pay_url; ?>';
        var payAppWin = null;
        var payAppPolling = null;
        var retryMax = 100;
        var totalPollCount = 0;

        function pollWhileUnpaid() {
            if (++totalPollCount > retryMax) {
                clearInterval(payAppPolling);
                location.href = '<?php echo $redirect_url; ?>';
                return;
            }
            bb.post("client/invoice/get", {hash: '<?php echo $hash; ?>'}, function (result) {
                switch (result.status) {
                    case 'paid':
                        location.href = '<?php echo $thankyou_url; ?>';
                        break;
                    case 'unpaid':
                        console.log('Unpaid: ' + totalPollCount);
                        return;
                    default:
                        location.href = '<?php echo $redirect_url; ?>';
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
</script>
