<?php

namespace Zifan\AddressParser;

class DataProvider implements DataProviderInterface
{
    /**
     * 文件路径，驱动为file时
     * @var string|null
     */
    private $path;

    /**
     * @param array $driver
     * @return DataProviderInterface
     */
    public function resolve(array $driver)
    {
        switch ($driver['driver']) {
            case 'database':
                return new $driver['model'];
            case 'file':
                $this->path = $driver['path'] ?? null;
                return $this;
        }
    }

    /**
     * @return array
     */
    protected function buildFromPluginFile()
    {
        $path = __DIR__.'/../config';

        $provinces = require $path . '/provinces.php';
        $cities    = require $path . '/cities.php';
        $districts = require $path . '/districts.php';

        foreach ($provinces as $pid => &$province) {
            foreach ($cities as $cid => $city) {
                if ($pid == $city['pid']) {

                    foreach ($districts as $did => $district) {
                        if ($cid == $district['pid']) {
                            unset($districts[$did]);
                            $city['children'][] = array_merge($district, ['id' => $did, 'level' => 3]);;
                        }
                    }

                    unset($cities[$cid]);
                    $province['children'][] = array_merge($city, ['id' => $pid, 'level' => 2]);
                }
            }
            $province = array_merge($province, ['id' => $pid, 'level' => 1]);
        }

        return $provinces;
    }

    /**
     * @return array|\ArrayAccess
     */
    public function toTree()
    {
        if ($this->path) {
            return require "$this->path";
        }

        return $this->buildFromPluginFile();
    }
}