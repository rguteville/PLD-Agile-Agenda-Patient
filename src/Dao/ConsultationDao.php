<?php
require_once __DIR__ . '/../Model/Consultation.php';

class ConsultationDao {
    private $db;
    private $tableName = "consultation"; // Nom de votre table SQL

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    // Récupérer une consultation par son ID pour reconstituer l'objet et son état
    public function getById($id) {
        $query = "SELECT * FROM " . $this->tableName . " WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // On instancie la consultation avec le statut récupéré de la BDD
            // Le constructeur de Consultation se chargera d'initialiser le bon State (WaitingState, etc.)
            $consultation = new Consultation($row['status']);
            $consultation->setId($row['id']);
            $consultation->setPatientId($row['patient_id']);
            return $consultation;
        }

        return null;
    }

    // Mettre à jour uniquement le statut en base après un changement d'état
    public function updateStatus($id, $status) {
        $query = "UPDATE " . $this->tableName . " SET status = :status WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    // Créer une nouvelle consultation initiale (par défaut en 'WAITING')
    public function create($patientId) {
        $query = "INSERT INTO " . $this->tableName . " (patient_id, status) VALUES (:patient_id, 'WAITING')";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
?>