// ===================================================================
// MODULE SUPABASE API CLIENT
// ===================================================================

// Déterminer le chemin API en fonction du contexte
function getApiBaseUrl() {
    if (window.location.pathname.includes('/web/')) {
        return '/web/api/index.php';
    }
    return '/api/index.php';
}

const SupabaseClient = {
    apiUrl: getApiBaseUrl(),
    
    // ===================================================================
    // DOSSIERS - CRUD
    // ===================================================================
    
    /**
     * Récupérer tous les dossiers
     */
    async getDossiers(filters = {}) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'dossiers');
            params.append('method', 'GET');
            
            if (filters.commune) params.append('commune', filters.commune);
            if (filters.situation) params.append('situation', filters.situation);
            
            const url = this.apiUrl + '?' + params.toString();
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.status === 200) {
                return result.data || [];
            } else {
                console.error('Erreur:', result.message);
                return [];
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return [];
        }
    },
    
    /**
     * Récupérer un dossier par ID
     */
    async getDossier(id) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'dossiers/' + id);
            params.append('method', 'GET');
            
            const response = await fetch(this.apiUrl + '?' + params.toString());
            const result = await response.json();
            
            if (result.status === 200) {
                return result.data;
            } else {
                console.error('Erreur:', result.message);
                return null;
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return null;
        }
    },
    
    /**
     * Créer un nouveau dossier
     */
    async createDossier(dossier) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'dossiers');
            params.append('method', 'POST');
            
            if (dossier.situation_dossier === 'IMCOMPLETE') {
                dossier.situation_dossier = 'INCOMPLETE';
            }

            const response = await fetch(this.apiUrl + '?' + params.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(dossier)
            });
            
            const contentType = response.headers.get('content-type');
            const responseText = await response.text();
            console.log('POST /dossiers - status:', response.status, 'type:', contentType);
            console.log('Response body:', responseText);
            
            if (!contentType || !contentType.includes('application/json')) {
                console.error('❌ Serveur a retourné du contenu non-JSON:', responseText);
                return { success: false, message: 'Erreur serveur: réponse non-JSON' };
            }
            
            const result = JSON.parse(responseText);
            console.log('Parsed result:', result);
            
            if (result.status === 201 || result.status === 200) {
                return { success: true, data: result.data };
            } else {
                console.error('Erreur API:', result);
                return { success: false, message: result.message };
            }
        } catch (error) {
            console.error('❌ Erreur lors de createDossier:', error);
            return { success: false, message: error.message };
        }
    },
    
    /**
     * Mettre à jour un dossier
     */
    async updateDossier(id, updates) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'dossiers/' + id);
            params.append('method', 'PUT');
            
            const response = await fetch(this.apiUrl + '?' + params.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(updates)
            });
            
            const result = await response.json();
            
            if (result.status === 200) {
                return { success: true, data: result.data };
            } else {
                return { success: false, message: result.message };
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return { success: false, message: error.message };
        }
    },
    
    /**
     * Supprimer un dossier
     */
    async deleteDossier(id) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'dossiers/' + id);
            params.append('method', 'DELETE');
            
            const response = await fetch(this.apiUrl + '?' + params.toString(), {
                method: 'POST'
            });
            
            const result = await response.json();
            
            if (result.status === 200) {
                return { success: true };
            } else {
                return { success: false, message: result.message };
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return { success: false, message: error.message };
        }
    },
    
    // ===================================================================
    // STATISTIQUES
    // ===================================================================
    
    /**
     * Récupérer les statistiques
     */
    async getStats() {
        try {
            const params = new URLSearchParams();
            params.append('route', 'stats');
            params.append('method', 'GET');
            
            const response = await fetch(this.apiUrl + '?' + params.toString());
            const result = await response.json();
            
            if (result.status === 200) {
                return result.data;
            } else {
                console.error('Erreur:', result.message);
                return null;
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return null;
        }
    },
    
    // ===================================================================
    // CONNEXION
    // ===================================================================
    
    /**
     * Vérifier la connexion à Supabase
     */
    async checkConnection() {
        try {
            const params = new URLSearchParams();
            params.append('route', 'check');
            params.append('method', 'GET');
            
            const response = await fetch(this.apiUrl + '?' + params.toString());
            const result = await response.json();
            
            if (result.status === 200) {
                console.log('✅ Connexion Supabase OK:', result.data);
                return true;
            } else {
                console.error('❌ Erreur connexion:', result.message);
                return false;
            }
        } catch (error) {
            console.error('❌ Erreur réseau:', error);
            return false;
        }
    },
    
    // ===================================================================
    // GESTION DES AVIS DES SERVICES
    // ===================================================================
    
    /**
     * Récupérer les avis d'un dossier
     */
    async getAvis(dossierId) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'avis');
            params.append('method', 'GET');
            params.append('dossier_id', dossierId);
            
            const response = await fetch(this.apiUrl + '?' + params.toString());
            const result = await response.json();
            
            if (result.status === 200) {
                return { success: true, data: result.data };
            } else {
                return { success: false, message: result.message };
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return { success: false, message: error.message };
        }
    },
    
    /**
     * Sauvegarder un avis pour un service
     */
    async saveAvis(avisData) {
        try {
            const params = new URLSearchParams();
            params.append('route', 'avis');
            params.append('method', 'POST');
            
            const response = await fetch(this.apiUrl + '?' + params.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(avisData)
            });
            
            const result = await response.json();
            
            if (result.status === 200 || result.status === 201) {
                return { success: true };
            } else {
                return { success: false, message: result.message };
            }
        } catch (error) {
            console.error('Erreur réseau:', error);
            return { success: false, message: error.message };
        }
    }
};

// Export pour utilisation dans d'autres scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SupabaseClient;
}
