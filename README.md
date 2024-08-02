# Magento Compatibility

Module functionality has been successfully tested using Magento technology stack advised on https://devdocs.magento.com/guides/v2.4/architecture/tech-stack.html as well as on all php 7.x and Magento 2.x versions.

For older Magento versions <= 2.3, please apply the patch from the module root folder `patch Controller/Checkout/Redirect.php magento23_and_lower.patch`

# Installation Guide

Steps to install PaySecure Payments E-commerce Gateway Magento 2 payment module from zip archive

- Create a directory `app/code/` (if does not exist already) in your Magento installation root and extract contents of archive into it, you should have `app/code/PaySecure/Payments` folder structure after that
- Enable maintenance mode if needed `php bin/magento maintenance:enable`
- Run `php bin/magento module:enable PaySecure_Payments --clear-static-content`
- Run `php bin/magento setup:upgrade`
- Run `php bin/magento setup:di:compile` if your Magento is in `production` mode.
- Run `php bin/magento setup:static-content:deploy` if your Magento is in `production` mode.
- Clear Magento cache `php bin/magento cache:flush`
- Disable maintenance mode if enabled `php bin/magento maintenance:disable`

# Configuration Guide
- Open the payment methods section on your magneto admin panel by navigating
    - Stores > Settings > Configuration
        - Sales > Payment Methods
            - Other Payment Methods > PaySecure Payments > Configure
- There you should see the "PaySecure Payments" configuration section. input your `Brand Id`  and `Secret key`, then hit "Save Config".
- Clear cache

Now when customers get to the checkout, they should see PaySecure Payments as one of the options.
