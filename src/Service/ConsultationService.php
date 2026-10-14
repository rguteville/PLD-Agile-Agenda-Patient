<?php
class ConsultationService {
    private $consultationDao;

    public function __construct($consultationDao) {
        $this->consultationDao = $consultationDao;
    }
    public function progressConsultation($consultationId) {
        
        $consultation = $consultationDao->getById($consultationId);
        $consultation->advance();
        $this->consultationDao->updateStatus($consultation->getId(), $consultation->getStatus());
    }
}
?>