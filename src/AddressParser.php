<?php

namespace Zifan\AddressParser;

use Symfony\Component\Process\Exception\RuntimeException;
use Zifan\LaravelAddressParser\Events\AfterFailedParsing;

/**
 * Class AddressParser
 * @link https://www.cnblogs.com/gqx-html/p/10790464.html
 * @link https://github.com/pupuk/address-smart-parse
 * @link https://ai.baidu.com/tech/nlp_apply/address?track=cp:ainsem|pf:pc|pp:chanpin-NLP|pu:NLP-dizhishibie|ci:|kw:10014588 #“百度地址识别：实现了姓名、街道维度的提取
 */
class AddressParser
{
    /**
     * The AddressParser version.
     *
     * @var string
     */
    const VERSION = '2.0.0';

    /**
     * @var array|\ArrayAccess
     */
    protected static $areas = [];

    /**
     * @var array
     */
    protected $config = [
        'dataProvider' => [
            'driver' => 'file'
        ]
    ];

    /**
     * AddressParser constructor.
     * @param array $options 配置项请参考 config 方法
     * @see config()
     */
    public function __construct(array $options = [])
    {
        $this->config($options);
    }

    /**
     * @param array $options Like: [
     *     'dataProvider' => [
     *         'driver' => 'file'
     *     ],
     *     'province_city_level_region_max_length' => 11,   // 省、市级地名的最大字符长度11 （黔西南布依族苗族自治州、克孜勒苏柯尔克孜自治州）
     *     'interference_words' => [],    // 干扰词
     *     'extra' => [                   // 额外提取字段
     *         'sub_district' => false,   // 村乡镇/街道（准确度低）
     *         'idn' => false,            // 身份证号
     *         'mobile' => false,         // 联系方式（手机号/座机号）
     *         'postcode' => false,       // 邮编
     *         'person' => false,         // 姓名（准确度低）
     *     ],
     *     'strict' => true,              // 是否对提取结果进行准确度校验
     * ]
     * @return $this
     */
    public function config(array $options)
    {
        $this->config = array_replace_recursive($this->config, $options);

        return $this;
    }

    /**
     * @return array|\ArrayAccess Like: [
     *     0 => [
     *         'id' => 1,
     *         'name' => '北京',
     *         'parent_id' => 0,
     *         'level' => 1,
     *         'children' => [
     *             [
     *                 'id' => 33, 'name' => '北京市', 'parent_id' => 1, 'level' => 2,
     *                 'children' => [...]
     *             ]
     *         ]
     *     ],
     *     ...
     * ]
     */
    public function getAreas()
    {
        if (empty(static::$areas)) {
            static::$areas = $this->dateProvider()->toTree();
        }

        // Validate data tree
        $first = current(static::$areas);
        if (!isset($first['name']) ||
            !isset($first['children']) ||
            !isset(current($first['children'])['name'])) {
            throw new RuntimeException('驱动提供的数据，不符合插件要求的树形结构！');
        }

        return static::$areas;
    }

    /**
     * @return DataProviderInterface
     */
    protected function dateProvider(): DataProviderInterface
    {
        $driver = $this->config['dataProvider'] ?? null;

        if (empty($driver['driver'])) {
            throw new RuntimeException('未设置数据驱动，请正确配置 dataProvider.driver');
        }

        return (new DataProvider)->resolve($driver);
    }

    /**
     * @return $this
     */
    public function release()
    {
        static::$areas = null;

        return $this;
    }

    /**
     * @param string $address
     * @return array
     */
    public function smart(string $address): array
    {
        // 排除干扰词：过滤掉收货地址中的常用说明字符
        if ($words = $this->config['interference_words'] ?? null) {
            $replace = array_fill(0, count($words), ' ');
            $address = str_replace($words, $replace, $address);
        }

        $result = $this->matchIfRegular($address)
            ?: $this->matchViaRegex($address)
            ?: $this->matchFuzzy($address);


        if ($result) {
            // $result = array_combine(['province', 'city', 'district', 'address'], $result);
            $result = $this->correct(...$result);

            if (empty($result['province']) || empty($result['city'])) {
                event(new AfterFailedParsing(func_get_args()[0], $result['address'])); // $result = event(...);
            }
        }

        if ($extra = array_filter($this->config['extra'] ?? [])) {
            $extra = $this->matchExtra($result['address'] ?? $address, $extra);
            $result = array_merge($result, $extra);
        }

        return $result ?: array_fill_keys(['province', 'city', 'district', 'address'], null);
    }

    /**
     * 规则的地址匹配
     * @param string $address
     * @return array|false
     * @internal 何为规则的地址，即省、市、区由特殊符号分隔开
     * 1、天猫导出 - 省市区带空格
     * 2、千牛导出 - 省市区带逗号
     * 3、京东导出 - 省市区带点"."
     * 4、其他
     */
    protected function matchIfRegular($address)
    {
        $result = preg_split('/[\s\.，,]+/u', $address, 4);
        if ($result === false || count($result) < 3) {
            return false;
        }

        // 只检查省、市级长度，区级划分可能不存在
        $limit = $this->config['province_city_level_region_max_length'] ?? 11;
        foreach (array_slice($result, 0, 2) as $value) {
            if (mb_strlen($value) > $limit) {
                return false;
            }
        }

        if (count($result) < 4) {
            array_splice($result, 2, 0, '');
        }

        return $result;
    }

    /**
     * @param $address
     * @return array|false
     */
    protected function matchViaRegex($address)
    {
        // 排除干扰词
        str_replace($chi = ['小区', '校区', '园区'], $rep = ['{小QU}', '{校QU}', '{园QU}'], $address);

        if (preg_match('/([\x{4e00}-\x{9fa5}]+省)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]+市)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{2,5}[市县区])?([^市县区]*$)/Uu', $address, $match) == false) {
            return false;
        }

        array_shift($match);

        $address = end($match);

        $match[key($match)] = str_replace($rep, $chi, $address);

        return $match ?? [];
    }
    /**
     * 模糊匹配
     * @param string $addr
     * @return array 省，市，区，街道地址
     * @author pupuk<pujiexuan@gmail.com>
     * @FIXME 优化
     */
    protected function matchFuzzy($addr)
    {
        $addr_origin = $addr;
        $addr = str_replace([' ', ','], ['', ''], $addr);
        $addr = str_replace('自治区', '省', $addr);
        $addr = str_replace('自治州', '州', $addr);

        $addr = str_replace('小区', '', $addr);
        $addr = str_replace('校区', '', $addr);

        $a1 = '';
        $a2 = '';
        $a3 = '';
        $address = '';

        if (mb_strpos($addr, '县') !== false && mb_strpos($addr, '县') < floor((mb_strlen($addr) / 3) * 2) ||
            mb_strpos($addr, '区') !== false && mb_strpos($addr, '区') < floor((mb_strlen($addr) / 3) * 2) ||
            mb_strpos($addr, '旗') !== false && mb_strpos($addr, '旗') < floor((mb_strlen($addr) / 3) * 2)) {

            if (mb_strstr($addr, '旗')) {
                $deep3_keyword_pos = mb_strpos($addr, '旗');
                $a3 = mb_substr($addr, $deep3_keyword_pos - 1, 2);
            }
            if (mb_strstr($addr, '区')) {
                $deep3_keyword_pos = mb_strpos($addr, '区');

                if (mb_strstr($addr, '市')) {
                    $city_pos = mb_strpos($addr, '市');
                    $zone_pos = mb_strpos($addr, '区');
                    $a3 = mb_substr($addr, $city_pos + 1, $zone_pos - $city_pos);
                } else {
                    $a3 = mb_substr($addr, $deep3_keyword_pos - 2, 3);
                }
            }
            if (mb_strstr($addr, '县')) {
                $deep3_keyword_pos = mb_strpos($addr, '县');

                if (mb_strstr($addr, '市')) {
                    $city_pos = mb_strpos($addr, '市');
                    $zone_pos = mb_strpos($addr, '县');
                    $a3 = mb_substr($addr, $city_pos + 1, $zone_pos - $city_pos);
                } else {

                    if (mb_strstr($addr, '自治县')) {
                        $a3 = mb_substr($addr, $deep3_keyword_pos - 6, 7);
                        if (in_array(mb_substr($a3, 0, 1), ['省', '市', '州'])) {
                            $a3 = mb_substr($a3, 1);
                        }
                    } else {
                        $a3 = mb_substr($addr, $deep3_keyword_pos - 2, 3);
                    }
                }
            }
            $address = mb_substr($addr_origin, $deep3_keyword_pos + 1);
        } else {
            if (mb_strripos($addr, '市')) {

                if (mb_substr_count($addr, '市') == 1) {
                    $deep3_keyword_pos = mb_strripos($addr, '市');
                    $a3 = mb_substr($addr, $deep3_keyword_pos - 2, 3);
                    $address = mb_substr($addr_origin, $deep3_keyword_pos + 1);
                } else if (mb_substr_count($addr, '市') >= 2) {
                    $deep3_keyword_pos = mb_strripos($addr, '市');
                    $a3 = mb_substr($addr, $deep3_keyword_pos - 2, 3);
                    $address = mb_substr($addr_origin, $deep3_keyword_pos + 1);
                }
            } else {

                $a3 = '';
                $address = $addr;
            }
        }

        if (mb_strpos($addr, '市') || mb_strstr($addr, '盟') || mb_strstr($addr, '州')) {
            if ($tmp_pos = mb_strpos($addr, '市')) {
                $a2 = mb_substr($addr, $tmp_pos - 2, 3);
            } else if ($tmp_pos = mb_strpos($addr, '盟')) {
                $a2 = mb_substr($addr, $tmp_pos - 2, 3);
            } else if ($tmp_pos = mb_strpos($addr, '州')) {

                if ($tmp_pos = mb_strpos($addr, '自治州')) {
                    $a2 = mb_substr($addr, $tmp_pos - 4, 5);
                } else {
                    $a2 = mb_substr($addr, $tmp_pos - 2, 3);
                }
            }
        } else {
            $a2 = '';
        }

        $r = array(
            'province' => $a1,
            'city' => $a2,
            'district' => $a3,
            'address' => $address,
        );

        return $r;
    }

    /**
     * 检查并校正省、市、区地址名称
     *
     * @param string $province
     * @param string $city
     * @param string $district
     * @param string $address
     * @return array  省，市，区：['province' => 'xxx', 'city' => 'yyy', 'district' => 'zzz']
     * @FIXME 优化：甘肃省东乡族自治县布楞沟村1号    渝北区渝北中学51200街道
     */
    protected function correct($province, $city, $district, $address = ''): array
    {
        $result = compact('province', 'city', 'district');

        if (empty($this->config['strict'])) {
            return $result;
        }

        $areas = $this->getAreas();

        if ($province) {
            $this->recursiveCorrectAsc($areas, $result);
            goto end;
        }
        // else
        if ($city) {
            $results = [];

            foreach ($areas as $area) {
                foreach ($area['children'] ?? [] as $item) {

                    if (mb_strpos($item['name'], $city) !== false) {
                        $result = compact('district');

                        $this->recursiveCorrectAsc($item['children'] ?? [], $result);

                        $results[] = array_merge([
                            'province' => $area['name'],
                            'city' => $item['name']
                        ], $result);
                    }
                }
            }

            foreach ($results as $result) {
                if ($district && isset($result['district']) || !$district) {
                    break;
                }
                unset($result);
            }
        }

        end: $result['address'] = $address;
        return $result ?? array_fill_keys(['province', 'city', 'district'], null);
    }

    /**
     * 递归矫正（顺序）
     * @param array|\ArrayAccess $areas
     * @param array $result
     */
    private function recursiveCorrectAsc($areas, &$result)
    {
        $key = key($result);
        $value = current($result);

        $result[$key] = null;

        if ($value == '') {
            goto end;
        }

        foreach ($areas as $area) {
            if (mb_strpos($area['name'], $value) !== false) {
                $result[$key] = $area['name'];

                if (next($result) && !empty($area['children'])) {
                    $this->recursiveCorrectAsc($area['children'], $result);
                }

                break;
            }
        }

        end: if (!isset($result[$key])) {
            while (next($result)) {
                $result[key($result)] = null;
            }
        }
    }

    /*private function recursiveCorrectDesc($areas, &$result)
    {
    }*/

    /**
     * 匹配额外字段：手机号(座机)，身份证号，姓名，邮编等信息
     *
     * @param string $string
     * @param array $extra
     * @return array
     */
    protected function matchExtra(string $string, array $extra): array
    {
        $compose = array_fill_keys(array_keys($extra), null);

        // 提取中国境内身份证号码
        if (isset($extra['idn']) &&
            preg_match('/\d{18}|\d{17}X/i', $string, $match)) {
            $compose['idn'] = strtoupper($match[0]);
            $string = str_replace($match[0], ' ', $string);
        }

        // 提取联系方式
        if (isset($extra['mobile'])) {
            // 去除手机号码中的短横线 如136-3333-6666 主要针对苹果手机
            $string = preg_replace('/0-|0?(\d{3})-(\d{4})-(\d{4})/', '$1$2$3', $string);
            // /\d{7,11}|\d{3,4}-\d{6,8}/
            if (preg_match('/1[0-9]{10}|(\d{3,4}\-)?\d{8}(\-\d{1,})?/U', $string, $match)) {
                $compose['mobile'] = $match[0];
                $string = str_replace($match[0], ' ', $string);
            }
        }

        // 提取邮编
        if (isset($extra['postcode']) &&
            preg_match('/\d{6}/U', $string, $match)) {
            $compose['postcode'] = $match[0];
            $string = str_replace($match[0], ' ', $string);
        }

        // 提取村乡镇/街道
        if (isset($extra['sub_district']) &&
            preg_match('/^[\x{4e00}-\x{9fa5}]+(?:街道|镇|村|乡)/Uu', $string, $match)) {
            $compose['sub_district'] = $match[0];
            $string = str_replace($match[0], ' ', $string);
        }

        // @FIXME 优化 提取姓名
        if (isset($extra['person']) &&
            !empty($result = preg_split('/\s+/', $string))) {
            $compose['person'] = $result[0];
            foreach ($result as $value) {
                if (mb_strlen($value) < mb_strlen($compose['person'])) {
                    $compose['person'] = $value;
                }
            }
            // $string = trim(str_replace($compose['name'], '', $string));
        }

        $compose['address'] = $string; // str_replace(' ', '', $string);

        return $compose;
    }
}