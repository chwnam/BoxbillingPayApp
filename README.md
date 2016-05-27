Boxbilling Payment Gateway: PayApp
==================================

박스빌링(http://www.boxbilling.com)용 페이앱(http://payapp.kr) 결제 모듈.

Git repository 특징상 소스 코드는 별도의 디렉토리 단위로 저장됩니다. 박스빌링에서 결제 모듈로 사용하기 위해서는 아래처럼 심볼릭 링크를 생성해주세요

```
cd <BoxBilling root>/bb-library/Payment/Adaptor
git clone https://github.com/chwnam/BoxbillingPayApp PayApp
ln -s PayApp/PayApp.php PayApp.php
```

Huraga 테마에서 아이콘 연결
========================

``bb-themes/huraga/assets/css/logos.css`` 파일을 수정해야 합니다.

css 파일 마지막에 다음과 같이 코드를 추가해 주세요

```css
.logo-PayApp{
    background: transparent url("../img/gateway_logos/payapp-icon-small.gif") no-repeat scroll 0% 0%;
    background-size: contain;
    width:32px;
    height: 32px;
    border: 0;
    margin: 10px;
}
```

그리고 ``bb-themes/huraga/assets/img/gateway_logos`` 디렉토리에 심볼링 링크를 걸어 줍니다.

```
cd <boxbilling-root>/bb-themes/huraga/assets/img/gateway_logos
ln -s ../../../../../bb-library/Payment/Adapter/BoxBillingPayApp/includes/payapp-icon-small.gif
```
