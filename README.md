# flourish-woocommerce

<img src="./flourish-logo.png" alt="Flourish Logo" height="250px;" />

👋 Hello!

This repository is to hold the PHP code that's utilized for the [Flourish](https://www.flourishsoftware.com/) WooCommerce WordPress plugin.

Flourish is a vertical seed-to-sale software platform that provides services across the United States for cultivation, manufacturing, distribution, and retail sales of cannabis.

This plugin allows users of the Flourish platform to seamlessly integrate items, inventory, customers, and orders into a flexible and powerful website for business to business, or business to consumer sales.

We do this by leveraging the [Flourish External API](https://api-docs.flourishsoftware.com/).

## 🔗 Helpful Links

* Flourish Software: [https://www.flourishsoftware.com](https://www.flourishsoftware.com/)
* WordPress: [https://wordpress.org/](https://wordpress.org/)
* WooCommerce: [https://woocommerce.com/](https://woocommerce.com/)

## 🥅 Goals

The goals of the plugin are to:

* Sync item and item updates from Flourish to WooCommerce
* Sync inventory from Flourish to WooCommerce
* Sync orders and customers / destinations from Flourish to WooCommerce
* Sync order updates from Flourish to WooCommerce

This is to give Flourish users the ability to easily open an online store.

## 🔌 Plugin Setup

For everything to work, and to get webhooks rolling, you'll want to get a few things setup.

1. Configure your WordPress installation to use "Post name" permalinks
    * Settings -> Permalinks -> Post name
1. Create webhooks that go to `domain.com/wp-json/flourish-woocommerce-plugin/v1/webhook`
    * Item
    * Retail Order
    * Outbound Order
    * Inventory Summary

## 🪪 License

Copyright (C) 2023-2024 [Flourish Software](https://www.flourishsoftware.com)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see [https://www.gnu.org/licenses/](https://www.gnu.org/licenses/).
