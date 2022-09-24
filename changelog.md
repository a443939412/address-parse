# Changelog

## V2.2.1
- 支持淘宝、京东复制黏贴的固定格式地址的解析
- 增强收货人姓名的匹配准确度（依旧准确度较低）

## V2.0
- 匹配时引入权重计算

## Version 1.0

### Added
- Created

## Memo: just for development
{
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/",
            "Zifan\\AddressParser\\": "packages/Zifan/addressparser/src"
        }
    },
    "repositories": {
        "zifan/addressparser": {
            "type": "path",
            "url": "packages/Zifan/addressparser",
            "options": {
                "symlink": true
            }
        }
    }
}