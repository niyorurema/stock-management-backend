<?php
// app/Models/SettingsModel.php

namespace App\Models;

use CodeIgniter\Model;

class SettingsModel extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = ['setting_key', 'setting_value', 'setting_type', 'description'];

    public function getSetting($key, $default = null)
    {
        $setting = $this->where('setting_key', $key)->first();
        return $setting ? $setting['setting_value'] : $default;
    }

    public function getAllSettings()
    {
        $settings = $this->findAll();
        $result = [];
        $booleanKeys = ['company_is_subject_to_vat', 'vat_exemption', 'ct_taxpayer', 'tl_taxpayer', 'tsce_tax', 'ott_tax', 'ebms_sync_stock_movements', 'ebms_sync_invoices'];

        foreach ($settings as $setting) {
            if ($setting['setting_type'] === 'checkbox' || in_array($setting['setting_key'], $booleanKeys, true)) {
                $result[$setting['setting_key']] = filter_var($setting['setting_value'], FILTER_VALIDATE_BOOLEAN);
            } else {
                $result[$setting['setting_key']] = $setting['setting_value'];
            }
        }
        return $result;
    }

    public function updateSetting($key, $value)
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $existing = $this->where('setting_key', $key)->first();
        if ($existing) {
            return $this->update($existing['id'], ['setting_value' => $value]);
        } else {
            $checkboxKeys = ['company_is_subject_to_vat', 'vat_exemption', 'ct_taxpayer', 'tl_taxpayer', 'tsce_tax', 'ott_tax', 'ebms_sync_stock_movements', 'ebms_sync_invoices'];
            $settingType = in_array($key, $checkboxKeys, true) ? 'checkbox' : 'text';

            return $this->insert([
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_type' => $settingType,
            ]);
        }
    }

    public function updateSettings($settings)
    {
        $success = true;
        foreach ($settings as $key => $value) {
            if (!$this->updateSetting($key, $value)) {
                $success = false;
            }
        }
        return $success;
    }

    public function uploadLogo($file)
    {
        $uploadPath = WRITEPATH . 'uploads/logo/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            return ['success' => false, 'message' => 'Type de fichier non autorisé'];
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Le fichier ne doit pas dépasser 2MB'];
        }

        $filename = 'logo_' . time() . '.' . $file->getExtension();
        $file->move($uploadPath, $filename);

        if ($file->hasMoved()) {
            $this->updateSetting('company_logo', 'uploads/logo/' . $filename);
            return ['success' => true, 'path' => 'uploads/logo/' . $filename];
        }

        return ['success' => false, 'message' => 'Erreur lors de l\'upload'];
    }
}
