<?php
require_once 'ConsultationStateInterface.php';

class InProgressState implements ConsultationStateInterface {
    public function nextStep($consultation) {
        // Passage de "En cours" à "Terminé"
        $consultation->setState(new FinishedState());
        $consultation->setStatus('FINISHED');
    }

    public function cancel($consultation) {
        throw new Exception("Impossible d'annuler une consultation déjà en cours.");
    }

    public function getStatusName(): string {
        return "En cours";
    }
}
?>