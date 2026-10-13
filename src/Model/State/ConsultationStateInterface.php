<?php
class Consultation {
    private $id;
    private $patientId;
    private $status; // Stocké en base de données (ex: 'WAITING', 'IN_PROGRESS')
    private $currentState; // Objet State actuel

    public function __construct($status = 'WAITING') {
        $this->status = $status;
        $this->initStateObject();
    }

    
    private function initStateObject() {
        switch ($this->status) {
            case 'IN_PROGRESS':
                $this->currentState = new InProgressState();
                break;
            case 'FINISHED':
                $this->currentState = new FinishedState();
                break;
            default:
                $this->currentState = new WaitingState();
                break;
        }
    }

    public function setState($state) {
        $this->currentState = $state;
    }

    public function setStatus($status) {
        $this->status = $status;
    }

   
    public function advance() {
        $this->currentState->nextStep($this);
    }

    public function cancelConsultation() {
        $this->currentState->cancel($this);
    }
}
?>