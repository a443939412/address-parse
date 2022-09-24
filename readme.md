# AddressParser

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Total Downloads][ico-downloads]][link-downloads]
[![Build Status][ico-travis]][link-travis]
[![StyleCI][ico-styleci]][link-styleci]

## 中文收货地址智能识别
参考链接：
* https://github.com/pupuk/address-smart-parse 感谢作者的分享
* https://www.cnblogs.com/gqx-html/p/10790464.html

Take a look at [contributing.md](contributing.md) to see a to do list.

Requirements
------------
 - PHP >= 7.0.0
 - Mbstring PHP Extension

## Installation

Via Composer

``` bash
$ composer require zifan/addressparser
```

## Usage

$options = ['strict' => true]

$parser = new AddressParser($options);

$parser->handle('浙江省杭州市滨江区西兴街道滨康路228号万福中心A座21楼');

## Array $options Like: [
    'dataProvider' => [
        'driver' => 'file'            // 驱动，默认file，其它方式（如数据模型）可自行扩展
        'path' => null,               // 指定省市区数据文件，默认从插件config文件夹中读取
    ],
    'enable_keywords_split' => false, // 是否启用关键词分割（如淘宝、京东在复制收货地址时带固定格式）拼多多不带关键字，只是格式固定
    'keywords' => [                   // enable_keywords_split 为 true 时才生效
        'person' => ['收货人', '收件人', '姓名'],
        'mobile' => ['手机号码', '手机', '联系方式', '电话号码', '电话'],
    ],
    'extra' => [                      // 额外提取字段
        'sub_district' => false,      // 村乡镇/街道（准确度低）
        'idn' => false,               // 身份证号
        'mobile' => false,            // 联系方式（手机号/座机号）
        'postcode' => false,          // 邮编
        'person' => false,            // 姓名（准确度低）
    ],
    'strict' => true,                 // 是否对提取结果进行准确度校验、补齐
]

#### 京东格式
姓名：张三
地址：浙江省杭州市滨江区 西兴街道 滨康路228号万福中心A座21楼

#### 淘宝格式
收货人: 张三
手机号码: 158********
所在地区: 浙江省杭州市滨江区西兴街道
详细地址: 滨康路228号万福中心A座21楼

## Change log

Please see the [changelog](changelog.md) for more information on what has changed recently.

## Contributing

Please see [contributing.md](contributing.md) for details and a todolist.

## Security

If you discover any security related issues, please email s443939412@163.com instead of using the issue tracker.

## Credits

- [zifan][link-author]
- [All Contributors][link-contributors]

## License

MIT. Please see the [license file](license.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/zifan/addressparser.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/zifan/addressparser.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/zifan/addressparser/master.svg?style=flat-square
[ico-styleci]: https://styleci.io/repos/12345678/shield

[link-packagist]: https://packagist.org/packages/zifan/addressparser
[link-downloads]: https://packagist.org/packages/zifan/addressparser
[link-travis]: https://travis-ci.org/zifan/addressparser
[link-styleci]: https://styleci.io/repos/12345678
[link-author]: https://github.com/a443939412
[link-contributors]: ../../contributors
