<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Receipt Width
    |--------------------------------------------------------------------------
    |
    | Width in characters for text-based receipts.
    | 58mm printers = 32 chars, 80mm printers = 48 chars.
    |
    */

    'receipt_width' => (int) env('PRINTER_RECEIPT_WIDTH', 48),

    /*
    |--------------------------------------------------------------------------
    | Printer Dots Per Line
    |--------------------------------------------------------------------------
    |
    | Hardware pixel width of the thermal printer head.
    | 58mm printers = 384 dots, 80mm printers = 576 dots.
    | This determines the image width sent to the printer.
    |
    */

    'dots_per_line' => (int) env('PRINTER_DOTS_PER_LINE', 576),

    /*
    |--------------------------------------------------------------------------
    | Image Scale Factor
    |--------------------------------------------------------------------------
    |
    | Multiplier for rendering resolution. Higher = sharper text but larger images.
    | 2 is the sweet spot for thermal printers.
    |
    */

    'image_scale' => (int) env('PRINTER_IMAGE_SCALE', 2),

    /*
    |--------------------------------------------------------------------------
    | Arabic Font Path
    |--------------------------------------------------------------------------
    |
    | Full path to the TrueType font used for Arabic text rendering.
    | Tahoma is recommended for clear Arabic rendering on thermal printers.
    |
    */

    'arabic_font' => env('PRINTER_ARABIC_FONT', 'C:\\Windows\\Fonts\\tahoma.ttf'),

    /*
    |--------------------------------------------------------------------------
    | Font Sizes
    |--------------------------------------------------------------------------
    |
    | Default font sizes for different receipt elements.
    | These are used before scaling by image_scale.
    |
    */

    'font_sizes' => [
        'logo'        => (int) env('PRINTER_FONT_LOGO', 36),
        'header'      => (int) env('PRINTER_FONT_HEADER', 28),
        'subheader'   => (int) env('PRINTER_FONT_SUBHEADER', 18),
        'body'        => (int) env('PRINTER_FONT_BODY', 16),
        'small'       => (int) env('PRINTER_FONT_SMALL', 13),
        'tiny'        => (int) env('PRINTER_FONT_TINY', 11),
        'total'       => (int) env('PRINTER_FONT_TOTAL', 30),
        'total_label' => (int) env('PRINTER_FONT_TOTAL_LABEL', 20),
        'table_header'=> (int) env('PRINTER_FONT_TABLE_HEADER', 14),
        'table_row'   => (int) env('PRINTER_FONT_TABLE_ROW', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Line Height
    |--------------------------------------------------------------------------
    |
    | Pixel height for each line of text in the receipt image.
    |
    */

    'line_height' => (int) env('PRINTER_LINE_HEIGHT', 28),

    /*
    |--------------------------------------------------------------------------
    | Margins
    |--------------------------------------------------------------------------
    |
    | Horizontal padding in pixels from the edge of the receipt.
    |
    */

    'margin_x' => (int) env('PRINTER_MARGIN_X', 12),

    /*
    |--------------------------------------------------------------------------
    | Currency Symbol
    |--------------------------------------------------------------------------
    |
    | Currency symbol displayed on receipts.
    |
    */

    'currency' => env('PRINTER_CURRENCY', 'NIS'),

    /*
    |--------------------------------------------------------------------------
    | Receipt Colors (RGB)
    |--------------------------------------------------------------------------
    |
    | Color scheme for the receipt template.
    | All values are 0-255 RGB.
    |
    */

    'colors' => [
        'black'         => [0, 0, 0],
        'white'         => [255, 255, 255],
        'receipt_bg'    => [255, 255, 255],

        // Header
        'header_bg'     => [220, 40, 40],
        'header_text'   => [255, 255, 255],

        // Customer card
        'card_bg'       => [245, 245, 245],
        'card_border'   => [200, 200, 200],
        'card_text'     => [60, 60, 60],
        'card_label'    => [120, 120, 120],

        // Table
        'table_header_bg'   => [220, 40, 40],
        'table_header_text' => [255, 255, 255],
        'table_border'      => [220, 220, 220],
        'table_row_alt'     => [250, 250, 250],
        'table_text'        => [40, 40, 40],
        'table_num'         => [80, 80, 80],

        // Separator
        'separator'     => [200, 200, 200],

        // Total box
        'total_bg'      => [220, 40, 40],
        'total_text'    => [255, 255, 255],
        'total_label_bg'=> [245, 245, 245],

        // Discount
        'discount_text' => [200, 50, 50],

        // Notes
        'note_text'     => [120, 120, 120],

        // Footer
        'footer_text'   => [140, 140, 140],
        'footer_accent' => [220, 40, 40],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Column Layout
    |--------------------------------------------------------------------------
    |
    | Column positions as percentage of receipt width.
    | Columns sum to 100. RTL: first column is rightmost.
    | name (40%) | qty (15%) | price (22%) | total (23%)
    |
    */

    'table_columns' => [
        ['key' => 'name',    'label' => 'الصنف',     'align' => 'right',  'width_pct' => 40],
        ['key' => 'quantity','label' => 'الكمية',    'align' => 'center', 'width_pct' => 15],
        ['key' => 'price',   'label' => 'السعر',     'align' => 'left',   'width_pct' => 22],
        ['key' => 'total',   'label' => 'المبلغ',    'align' => 'left',   'width_pct' => 23],
    ],

    /*
    |--------------------------------------------------------------------------
    | Receipt Layout
    |--------------------------------------------------------------------------
    |
    | Spacing and padding values (in pixels, before scale).
    |
    */

    'browsershot_chrome_path' => env('BROWSERSHOT_CHROME_PATH', null),

    'browsershot_node_path' => env('BROWSERSHOT_NODE_PATH', null),

    'browsershot_npm_path' => env('BROWSERSHOT_NPM_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Receipt Layout
    |--------------------------------------------------------------------------
    |
    | Spacing and padding values (in pixels, before scale).
    |
    */

    'layout' => [
        'section_gap'       => 16,
        'card_padding'      => 12,
        'card_radius'       => 6,
        'card_gap'          => 10,
        'table_header_height'=> 32,
        'table_row_height'   => 42,
        'table_name_max_width_pct' => 0.42,
        'total_box_radius'  => 8,
        'total_box_height'  => 65,
        'footer_gap'        => 20,
    ],

];
