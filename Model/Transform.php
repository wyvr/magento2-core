<?php

namespace Wyvr\Core\Model;

class Transform
{
    public function convertBoolAttributes(array $data, array $attributes = [])
    {
        if (empty($attributes)) {
            return $data;
        }
        foreach ($attributes as $attr) {
            if (array_key_exists($attr, $data)) {
                $data[$attr] = $this->toBool($data[$attr]);
            } else {
                $data[$attr] = false;
            }
        }
        return $data;
    }

    public function toBool($data): bool
    {
        return $data === '1';
    }

    public function toMultiselect($data, $attribute)
    {
        $splitValues = explode(',', $data);
        $label = "";
        foreach ($splitValues as $value) {
            $singleLabel = $this->toSelect($value, $attribute);
            if ($singleLabel) {
                $label .= $singleLabel . ', ';
            }
        }
        return trim(trim($label), ',');
    }

    public function toSelect($data, $attribute)
    {
        $option = array_filter($attribute->getOptions(), function ($value) use ($data) {
            return $value['value'] && $value['value'] == $data;
        });

        if (!$option || !is_array($option) || count($option) <= 0) {
            return '';
        }

        $label = reset($option)['label'];
        if (is_a($label, 'Magento\Framework\Phrase')) {
            return $label->getText();
        }
        return $label;
    }

    public function toNullableFloat($data)
    {
        if (empty($data)) {
            return null;
        } else {
            return floatval($data);
        }
    }
}
