<?php
require_once 'ConsultationStateInterface.php';

class WaitingState implements ConsultationStateInterface {
    public function nextStep($consultation) {
        // Passage de "En attente" à "En cours"
        $consultation->setState(new InProgressState());
        $consultation->setStatus('IN_PROGRESS');
    }

    public function cancel($consultation) {
        $consultation->setState(new CancelledState());
        $consultation->setStatus('CANCELLED');
    }

    public function getStatusName(): string {
        return "En attente";
    }
}
?>