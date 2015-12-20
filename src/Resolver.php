<?php

namespace Wpbootstrap;

class Resolver
{
    private $import;

    public function __construct()
    {
        $container = Container::getInstance();
        $this->import = $container->getImport();
    }

    public function resolveOptionReferences($references, $type)
    {
        wp_cache_delete('alloptions', 'options');

        foreach ($references as $key => $value) {
            if (is_integer($key)) {
                $currentValue = get_option($value, 0);
                $newId = $this->import->findTargetObjectId($currentValue, $type);
                if ($newId != 0) {
                    update_option($value, $newId);
                }
            } elseif (is_string($key) && is_string($value)) {
                $path = $value;
                $currentStruct = get_option($key, 0);
                try {
                    $currentValue = $this->getValue($currentStruct, $path);
                    $newId = $this->import->findTargetObjectId($currentValue, $type);
                    if ($newId != 0) {
                        $this->setValue($currentStruct, $path, $newId);
                        update_option($key, $currentStruct);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            } elseif (is_string($key) && is_array($value)) {
                $currentStruct = get_option($key, 0);
                $paths = $value;
                foreach ($paths as $path) {
                    $currentValue = $this->getValue($currentStruct, $path);
                    $newId = $this->import->findTargetObjectId($currentValue, $type);
                    if ($newId != 0) {
                        $this->setValue($currentStruct, $path, $newId);
                    }
                }
                update_option($key, $currentStruct);
            }
        }
    }

    private function getValue($obj, $path)
    {
        return eval('return $obj'.$path.';');
    }

    private function setValue(&$obj, $path, $value)
    {
        $evalStr = '$obj'.$path.'='.$value.'; return $obj;';
        $obj = eval($evalStr);
    }
}
