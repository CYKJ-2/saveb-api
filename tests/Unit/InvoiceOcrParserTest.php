<?php

namespace Tests\Unit;

use App\Services\InvoiceOcrParserService;
use PHPUnit\Framework\TestCase;

class InvoiceOcrParserTest extends TestCase
{
    public function test_paypal_cards_use_quantity_line_and_exclude_store_credit(): void
    {
        $result = (new InvoiceOcrParserService())->parse(<<<'TEXT'
150000 Issued : Aug 22, 2026
$452.86
Paid
Items
fashion clothing Iw1 $189.00
1x $189.00
fashion clothing gu 2 $159.00
1x $159.00
fashion clothing pu 3 $159.00
1x $159.00
Store credit -$30.30
1x -$30.30
Subtotal $476.70
Discount (5%) -$23.84
Total $452.86
TEXT);
        $fields = $result['fields'];
        $this->assertCount(3, $fields['items']);
        $this->assertSame(['fashion clothing Iw1', 'fashion clothing gu 2', 'fashion clothing pu 3'], array_column($fields['items'], 'description'));
        $this->assertSame([1, 1, 1], array_column($fields['items'], 'quantity'));
        $this->assertSame([189.0, 159.0, 159.0], array_column($fields['items'], 'price'));
        $this->assertSame('Paid', $fields['invoice_status']);
        $this->assertSame('Paid', $result['paymentStatus']);
        $this->assertSame(452.86, $fields['amount_usd']);
        $this->assertSame(5.0, $fields['percentage_discount']);
        $this->assertSame('2026-08-22', $fields['order_date']);
    }

    public function test_card_line_totals_and_product_numbers_are_not_unit_prices_or_quantities(): void
    {
        $result = (new InvoiceOcrParserService())->parse(<<<'TEXT'
Items
Bag 99
$100.00
2 x $50.00
Tote 3 $60.00
3 × $20.00
Store credit
-$10.00
1 x -$10.00
Shipping $5.00
1 x $5.00
Subtotal $155.00
Total $155.00
TEXT);
        $this->assertSame(['Bag 99', 'Tote 3'], array_column($result['fields']['items'], 'description'));
        $this->assertSame([2, 3], array_column($result['fields']['items'], 'quantity'));
        $this->assertSame([50.0, 20.0], array_column($result['fields']['items'], 'price'));
    }

    public function test_ambiguous_ocr_status_is_not_guessed_as_paid(): void
    {
        $parser = new InvoiceOcrParserService();
        $this->assertNull($parser->parse("$452.86\nPal\nTotal $452.86")['paymentStatus']);
        $this->assertSame('Unpaid', $parser->parse("Invoice Status: Unpaid\nTotal $452.86")['paymentStatus']);
    }

    public function test_chinese_product_names_are_rows_and_multiline_products_are_added(): void
    {
        $result = (new InvoiceOcrParserService())->parse(<<<'TEXT'
物品 数量 单价 金额
商品手袋 2 US$100.00 US$200.00
产品帆布包
1
US$50.00
US$50.00
fashion clothing 1ch US$389.00
小计 US$639.00
Discount US$39.00
Total US$600.00
TEXT);
        $this->assertCount(3, $result['fields']['items']);
        $this->assertSame(['商品手袋', '产品帆布包', 'fashion clothing'], array_column($result['fields']['items'], 'description'));
        $this->assertSame([2, 1, 1], array_column($result['fields']['items'], 'quantity'));
        $this->assertSame([100.0, 50.0, 389.0], array_column($result['fields']['items'], 'price'));
    }

    public function test_pasted_source_field_names_map_to_business_fields_without_changing_added_date(): void
    {
        $result = (new InvoiceOcrParserService())->parse(<<<'TEXT'
添加日期：2026-08-01
订单日期：2026-09-07
收件人姓名：Jane Smith
电话号码：+1 202-555-0123
国家：United States
地址：123 Example Street
客户邮箱：buyer@example.test
收款账号：seller@example.test
Invoice状态：Pending
订单金额（美元）：250.00
Invoice链接：https://example.test/invoice/123
礼盒：无
加急派送：否
TEXT);
        $fields = $result['fields'];
        $this->assertSame('2026-09-07', $fields['order_date']);
        $this->assertArrayNotHasKey('invoice_date', $fields);
        $this->assertSame('Jane Smith', $fields['customer_full_name']);
        $this->assertSame('+1 202-555-0123', $fields['phone_number']);
        $this->assertSame('seller@example.test', $fields['recipient_paypal']);
        $this->assertSame(250.0, $fields['amount_usd']);
        $this->assertSame('Pending', $fields['invoice_status']);
        $this->assertSame('None', $fields['gift_box']);
        $this->assertFalse($fields['expedited_shipping']);
    }

    public function test_all_original_payment_statuses_are_recognized(): void
    {
        foreach (['Paid', 'Pending', 'Overdue', 'Unpaid', 'Refunded', 'Failed'] as $status) {
            $result = (new InvoiceOcrParserService())->parse('Invoice Status: ' . $status);
            $this->assertSame($status, $result['fields']['invoice_status']);
            $this->assertSame($status, $result['paymentStatus']);
        }
    }

    public function test_paypal_customer_seller_amount_and_items_are_mapped_to_form_fields(): void
    {
        $result = (new InvoiceOcrParserService())->parse(<<<'TEXT'
Invoice
SAVEB Store
sales@example.test
Invoice date: September 7, 2026
Due date: September 20, 2026
Paid
Bill To
Jane Smith
buyer@example.test
Phone: +1 202-555-0123
123 Example Street
Boston MA 02110
United States
Items Quantity Price Amount
Leather Bag 2 $100.00 $200.00
Canvas Tote 1 $50.00 $50.00
Subtotal $250.00
Discount 10%
Paid Total USD 225.00
Invoice link: https://example.test/invoice/123
No gift box
No express shipping
TEXT);
        $fields = $result['fields'];
        $this->assertSame('Jane Smith', $fields['customer_full_name']);
        $this->assertSame('buyer@example.test', $fields['customer_email']);
        $this->assertSame('sales@example.test', $fields['recipient_paypal']);
        $this->assertSame('2026-09-07', $fields['order_date']);
        $this->assertSame('+1 202-555-0123', $fields['phone_number']);
        $this->assertSame('United States', $fields['country']);
        $this->assertSame("123 Example Street\nBoston MA 02110", $fields['address']);
        $this->assertSame(225.0, $fields['amount_usd']);
        $this->assertSame('https://example.test/invoice/123', $fields['invoice_link']);
        $this->assertCount(2, $fields['items']);
        $this->assertSame(2, $fields['items'][0]['quantity']);
        $this->assertSame(100.0, $fields['items'][0]['price']);
        $this->assertSame('Leather Bag', $fields['items'][0]['product_name']);
        $this->assertSame(10.0, $fields['percentage_discount']);
        $this->assertSame('None', $fields['gift_box']);
        $this->assertFalse($fields['expedited_shipping']);
        $this->assertSame('Paid', $result['paymentStatus']);
    }

    public function test_missing_customer_fields_are_not_guessed_from_seller_or_due_date(): void
    {
        $result = (new InvoiceOcrParserService())->parse("Seller Email: seller@example.test\nDue date: 2026-09-08\nSubtotal USD 200.00\nAmount Due USD 0.00\nUnpaid");
        $this->assertArrayNotHasKey('customer_email', $result['fields']);
        $this->assertArrayNotHasKey('order_date', $result['fields']);
        $this->assertArrayNotHasKey('amount_usd', $result['fields']);
        $this->assertSame('seller@example.test', $result['fields']['recipient_paypal']);
        $this->assertSame('Unpaid', $result['paymentStatus']);
    }

    public function test_foreign_currency_is_not_silently_labelled_usd(): void
    {
        $result = (new InvoiceOcrParserService())->parse("Items Quantity Price Amount\nBag 2 EUR 100.00 EUR 200.00\nTotal EUR 200.00");
        $this->assertSame('EUR', $result['currency']);
        $this->assertArrayNotHasKey('amount_usd', $result['fields']);
        $this->assertArrayNotHasKey('price', $result['fields']['items'][0]);
        $this->assertContains('currency_conversion_required', $result['warnings']);
    }

    public function test_total_on_following_line_and_invalid_dates(): void
    {
        $result = (new InvoiceOcrParserService())->parse("Invoice Date: 2026-02-30\nInvoice Total\nUSD 1,234.50");
        $this->assertSame(1234.5, $result['fields']['amount_usd']);
        $this->assertArrayNotHasKey('order_date', $result['fields']);
    }

    public function test_chinese_paypal_labels_and_ocr_currency_confusion(): void
    {
        $result = (new InvoiceOcrParserService())->parse("卖方\nseller@example.test\n已 发 出：2026 年 7 月 17 日\n账单 寄 送 至\nbuyer@example.test\n物品\n商品 单价\n共计 Uss200.00");
        $this->assertSame(200.0, $result['fields']['amount_usd']);
        $this->assertSame('2026-07-17', $result['fields']['order_date']);
        $this->assertSame('buyer@example.test', $result['fields']['customer_email']);
        $this->assertSame('seller@example.test', $result['fields']['recipient_paypal']);
    }

    public function test_multiline_items_and_explicit_dollar_currency(): void
    {
        $parser = new InvoiceOcrParserService();
        $result = $parser->parse("Items\nQuantity\nPrice\nLeather Bag\n2\nUSD 100.00\nTotal USD 200.00");
        $this->assertSame('Leather Bag', $result['fields']['items'][0]['product_name']);
        $this->assertSame(2, $result['fields']['items'][0]['quantity']);
        $result = $parser->parse('Total $200.00 CAD');
        $this->assertSame('CAD', $result['currency']);
        $this->assertArrayNotHasKey('amount_usd', $result['fields']);
    }
}
