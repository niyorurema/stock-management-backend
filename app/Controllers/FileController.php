<?php
// app/Controllers/FileController.php

namespace App\Controllers;

use CodeIgniter\Controller;

class FileController extends Controller
{
    /**
     * GET /uploads/logo/(:any) - Servir le logo
     */
    public function getLogo($filename = null)
    {
        $path = WRITEPATH . 'uploads/logo/' . $filename;
        
        if (file_exists($path)) {
            $mime = mime_content_type($path);
            return $this->response
                ->setHeader('Content-Type', $mime)
                ->setHeader('Cache-Control', 'public, max-age=86400')
                ->setBody(file_get_contents($path));
        }
        
        return $this->response->setStatusCode(404);
    }
    
    /**
     * GET /uploads/temp/(:any) - Servir les fichiers temporaires
     */
    public function getTemp($filename = null)
    {
        $path = WRITEPATH . 'temp/' . $filename;
        
        if (file_exists($path)) {
            $mime = mime_content_type($path);
            return $this->response
                ->setHeader('Content-Type', $mime)
                ->setBody(file_get_contents($path));
        }
        
        return $this->response->setStatusCode(404);
    }

  public function serve($path = null)
    {
        // Construire le chemin complet
        $filePath = FCPATH . 'uploads/' . $path;
        
        // Vérifier si le fichier existe
        if (empty($path) || !file_exists($filePath) || is_dir($filePath)) {
            return $this->response->setStatusCode(404);
        }
        
        // Obtenir le type MIME
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
        ];
        
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        
        // Désactiver la compression pour les fichiers
        $this->response->setHeader('Content-Type', $contentType);
        $this->response->setHeader('Content-Length', filesize($filePath));
        $this->response->setHeader('Content-Disposition', 'inline; filename="' . basename($filePath) . '"');
        
        // Lire et envoyer le fichier
        $content = file_get_contents($filePath);
        $this->response->setBody($content);
        
        return $this->response;
    }
}