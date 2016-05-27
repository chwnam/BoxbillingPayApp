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
    <div>
        <p><a href="#" class="btn btn-primary" id="payapp-payment">클릭하여 페이앱 결제 진행</a></p>
        <p>위의 버튼(혹은 링크)를 클랙하면 아래 그림과 같은 새 창이 열립니다. 이 창에서 결제를 진행하시면 됩니다.</p>
        <p><img src="/bb-library/Payment/Adapter/<?php echo $directory; ?>/includes/payapp-screenshot.png"/></p>
    </div>
</div>

<script type="text/javascript">
    var payAppObj = {
        'payURL': '<?php echo $pay_url; ?>',
        'thankYouUrl': '<?php echo $thankyou_url; ?>',
        'redirectURL': '<?php echo $redirect_url; ?>',
        'hash': '<?php echo $hash; ?>'
    };
</script>

<script type="text/javascript" src="/bb-library/Payment/Adapter/<?php echo $directory; ?>/includes/payapp-payment.js">
</script>
