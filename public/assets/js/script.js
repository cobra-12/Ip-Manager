/**
 * Script principal pour la gestion des IP
 * Contient les fonctions pour la gestion des formulaires modaux et de la recherche
 */

// Initialisation au chargement du DOM
document.addEventListener('DOMContentLoaded', function() {
    // Gestion du bouton d'ajout d'IP
    const showAddFormBtn = document.getElementById('showAddForm');
    if (showAddFormBtn) {
        showAddFormBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showAddForm();
        });
    }

    // Initialisation de la recherche
    initializeSearch();

    // Initialisation des gestionnaires de formulaires
    initializeForms();
});

/**
 * Affiche le formulaire d'ajout d'IP
 */
function showAddForm() {
    const addIpForm = document.getElementById('addIpForm');
    if (addIpForm) {
        addIpForm.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Empêcher le défilement
    }
}

/**
 * Ferme le formulaire d'ajout d'IP
 */
function closeAddForm() {
    const addIpForm = document.getElementById('addIpForm');
    if (addIpForm) {
        addIpForm.style.display = 'none';
        document.body.style.overflow = 'auto'; // Restaurer le défilement
    }
}

/**
 * Affiche le formulaire de modification de client
 * @param {number} id - ID de l'IP
 * @param {string} customerName - Nom du client actuel
 */
function showEditCustomer(id, customerName, city) {
    const modal = document.getElementById('editCustomerModal');
    const idInput = document.getElementById('editCustomerId');
    const nameInput = document.getElementById('editCustomerName');
    const citySelect = document.getElementById('editCustomerCity');
    
    if (modal && idInput && nameInput) {
        idInput.value = String(id);
        nameInput.value = customerName || '';
        if (citySelect && typeof city === 'string') {
            citySelect.value = city;
        }
        modal.style.display = 'flex';
    } else {
        console.error('Éléments de la modale de modification de client non trouvés');
    }
}

/**
 * Affiche le formulaire de modification d'IP
 * @param {number} id - ID de l'IP
 * @param {string} ipAddress - Adresse IP
 * @param {string} vlan - VLAN
 */
function showEditIP(id, ipAddress, vlan) {
    const modal = document.getElementById('editIPModal');
    const idInput = document.getElementById('editIPId');
    const ipInput = document.getElementById('editIPAddress');
    const vlanInput = document.getElementById('editVLAN');
    
    if (modal && idInput && ipInput && vlanInput) {
        idInput.value = String(id);
        ipInput.value = ipAddress || '';
        vlanInput.value = vlan || '';
        modal.style.display = 'flex';
    } else {
        console.error('Éléments de la modale de modification d\'IP non trouvés');
    }
}

/**
 * Affiche le formulaire d'utilisation d'IP
 * @param {number} id - ID de l'IP
 */
function useIP(id) {
    const modal = document.getElementById('useIPModal');
    const idInput = document.getElementById('useIPId');
    
    if (modal && idInput) {
        idInput.value = id;
        modal.style.display = 'flex';
    } else {
        console.error('Éléments de la modale d\'utilisation d\'IP non trouvés');
    }
}

/**
 * Ferme une modale
 * @param {string} modalId - ID de la modale à fermer
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Initialise la fonctionnalité de recherche
 */
function initializeSearch() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const table = document.querySelector('table tbody');
        if (!table) return;

        const rows = table.getElementsByTagName('tr');
        for (let row of rows) {
            const cells = row.getElementsByTagName('td');
            let found = false;
            
            // Recherche dans toutes les colonnes
            for (let cell of cells) {
                if (cell.textContent.toLowerCase().includes(searchTerm)) {
                    found = true;
                    break;
                }
            }
            
            row.style.display = found ? '' : 'none';
        }
    });
}

/**
 * Initialise les gestionnaires d'événements pour les formulaires
 */
function initializeForms() {
    // Formulaire de modification de client
    const editCustomerForm = document.getElementById('editCustomerForm');
    if (editCustomerForm) {
        editCustomerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(this, this.action || 'update_customer.php');
        });
    }

    // Formulaire de modification d'IP
    const editIPForm = document.getElementById('editIPForm');
    if (editIPForm) {
        editIPForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(this, this.action || 'update_ip_vlan.php');
        });
    }

    // Formulaire d'utilisation d'IP
    const useIPForm = document.getElementById('useIPForm');
    if (useIPForm) {
        useIPForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(this, 'use_ip.php');
        });
    }

    // Formulaire d'ajout d'IP sur la page d'accueil (overlay #addIpForm)
    const homeAddForm = document.querySelector('#addIpForm form.add-form');
    if (homeAddForm) {
        homeAddForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Supprimer anciennes alertes
            const oldAlerts = homeAddForm.querySelectorAll('.alert');
            oldAlerts.forEach(a => a.remove());

            const formData = new FormData(homeAddForm);
            const submitBtn = homeAddForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            fetch(homeAddForm.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => {
                if (!res.ok) throw new Error('Erreur réseau');
                return res.json();
            })
            .then(data => {
                // Créer une alerte
                const alert = document.createElement('div');
                alert.className = `alert ${data.success ? 'alert-success' : 'alert-error'}`;
                alert.textContent = data.message || (data.success ? 'Adresse IP ajoutée avec succès' : 'Une erreur est survenue');

                // Détails d'erreurs si disponibles
                if (!data.success && Array.isArray(data.errors) && data.errors.length) {
                    const ul = document.createElement('ul');
                    ul.style.marginTop = '8px';
                    data.errors.forEach(msg => {
                        const li = document.createElement('li');
                        li.textContent = msg;
                        ul.appendChild(li);
                    });
                    alert.appendChild(ul);
                }

                // Insérer l'alerte en haut du formulaire
                homeAddForm.insertAdjacentElement('afterbegin', alert);

                // Si succès, on reset le formulaire mais on laisse l'overlay ouvert
                if (data.success) {
                    homeAddForm.reset();
                }
            })
            .catch(err => {
                const alert = document.createElement('div');
                alert.className = 'alert alert-error';
                alert.textContent = `Une erreur est survenue. ${err.message || ''}`.trim();
                homeAddForm.insertAdjacentElement('afterbegin', alert);
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }
}

/**
 * Soumet un formulaire via AJAX
 * @param {HTMLFormElement} form - Élément formulaire
 * @param {string} url - URL de soumission
 */
function submitForm(form, url) {
    const formData = new FormData(form);
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Erreur réseau');
        }
        return response.text();
    })
    .then(() => {
        window.location.reload();
    })
    .catch(error => {
        console.error('Erreur lors de la soumission du formulaire:', error);
        alert('Une erreur est survenue. Veuillez réessayer.');
    });
}

// Gestionnaires d'événements globaux

/**
 * Affiche une boîte de dialogue de confirmation avant la suppression
 * @param {HTMLFormElement} form - Le formulaire à soumettre
 */
function confirmDelete(form) {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette entrée ? Cette action est irréversible.')) {
        form.submit();
    }
    return false;
}

// Soumet le formulaire d'ajout d'IP avec validation
function submitAddIPForm(event) {
    event.preventDefault();
    
    const form = event.target;
    if (!form || !form.action) return false;
    
    // Réinitialiser les messages d'erreur
    document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
    document.getElementById('formError').style.display = 'none';
    
    // Désactiver le bouton de soumission et afficher le spinner
    const submitBtn = form.querySelector('button[type="submit"]');
    const btnText = submitBtn.querySelector('.btn-text');
    const spinner = submitBtn.querySelector('.spinner-border');
    
    submitBtn.disabled = true;
    if (btnText) btnText.textContent = 'Enregistrement...';
    if (spinner) spinner.classList.remove('d-none');
    
    // Récupérer les données du formulaire
    const formData = new FormData(form);
    
    // Envoyer la requête AJAX
    fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => {
                throw err;
            });
        }
        return response.json();
    })
    .then(data => {
        // Supprimer les anciens messages d'alerte
        const existingAlerts = document.querySelectorAll('#addIPForm .alert');
        existingAlerts.forEach(alert => alert.remove());
        
        if (data.success) {
            // Afficher un message de succès
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success';
            successAlert.textContent = data.message || 'Adresse IP ajoutée avec succès';
            form.prepend(successAlert);
            
            // Réinitialiser le formulaire
            form.reset();
            
            // Rediriger vers la page de gestion après un court délai
            setTimeout(() => {
                window.location.href = 'gestion_ip.php';
            }, 1500);
        } else {
            // Afficher les erreurs de validation
            if (data.errors) {
                Object.entries(data.errors).forEach(([field, message]) => {
                    const errorElement = document.getElementById(`${field}_error`);
                    if (errorElement) {
                        errorElement.textContent = message;
                    }
                });
            } else if (data.message) {
                showAlert('error', data.message);
            }
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        let errorMessage = 'Une erreur est survenue lors de la communication avec le serveur';
        
        if (error.message) {
            errorMessage = error.message;
        } else if (error.error) {
            errorMessage = error.error;
        }
        
        showAlert('error', errorMessage);
    })
    .finally(() => {
        // Réactiver le bouton de soumission et masquer le spinner
        submitBtn.disabled = false;
        if (btnText) btnText.textContent = 'Enregistrer';
        if (spinner) spinner.classList.add('d-none');
    });
    
    return false;
}

// Affiche une alerte
function showAlert(type, message, isHtml = false) {
    if (!message) return;
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    
    if (isHtml) {
        alertDiv.innerHTML = message;
    } else {
        alertDiv.textContent = message;
    }
    
    const form = document.getElementById('addIPForm');
    if (form) {
        form.prepend(alertDiv);
        
        // Faire défiler jusqu'à l'alerte
        alertDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Supprimer l'alerte après 5 secondes
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

// Fermer le formulaire en cliquant sur l'overlay
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('overlay')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
});

// Fermer le formulaire avec la touche Échap
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.overlay');
        modals.forEach(modal => {
            modal.style.display = 'none';
        });
        document.body.style.overflow = 'auto';
    }
});