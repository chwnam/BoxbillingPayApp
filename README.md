Boxbilling Payment Gateway: PayApp
==================================

박스빌링(http://www.boxbilling.com)용 페이앱(http://payapp.kr) 결제 모듈.

Git repository 특징상 소스 코드는 별도의 디렉토리 단위로 저장됩니다. 박스빌링에서 결제 모듈로 사용하기 위해서는 아래처럼 심볼릭 링크를 생성해주세요

```
cd <BoxBilling root>/bb-library/Payment/Adaptor
git clone https://github.com/chwnam/BoxbillingPayApp PayApp
ln -s PayApp/PayApp.php PayApp.php
```
