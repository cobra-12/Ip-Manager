// app.js - Ce fichier contient le code JavaScript pour la fonctionnalité côté client, y compris la vérification en temps réel des adresses IP et les mises à jour dynamiques de l'interface utilisateur.

document.addEventListener('DOMContentLoaded', function() {
    const ipInput = document.getElementById('ip-input');
    const statusIndicator = document.getElementById('status-indicator');
    const ipTableBody = document.getElementById('ip-table-body');
    const addIpForm = document.getElementById('addIpForm');
    const assignCustomerForm = document.getElementById('assignCustomerForm');
    const availableIpList = document.getElementById('availableIpList');
    const showAddFormBtn = document.getElementById('showAddForm');

    // Vérification en temps réel de l'adresse IP
    ipInput.addEventListener('input', function() {
        const ipValue = ipInput.value;
        if (ipValue) {
            checkIp(ipValue);
        } else {
            statusIndicator.textContent = '';
            statusIndicator.style.backgroundColor = '';
        }
    });

    // Fonction pour vérifier l'adresse IP
    function checkIp(ip) {
        fetch(`api/check_ip.php?ip=${encodeURIComponent(ip)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    statusIndicator.textContent = 'UP';
                    statusIndicator.style.backgroundColor = 'green';
                } else {
                    statusIndicator.textContent = 'DOWN';
                    statusIndicator.style.backgroundColor = 'red';
                }
            })
            .catch(error => console.error('Erreur lors de la vérification de l\'IP:', error));
    }

    // Fonction pour ajouter un nouvel appareil
    document.getElementById('add-device-form').addEventListener('submit', function(event) {
        event.preventDefault();
        const formData = new FormData(this);
        fetch('api/add_device.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadDevices();
                this.reset();
            } else {
                alert('Erreur lors de l\'ajout de l\'appareil.');
            }
        })
        .catch(error => console.error('Erreur lors de l\'ajout de l\'appareil:', error));
    });

    // Fonction pour charger les appareils
    function loadDevices() {
        fetch('api/get_devices.php')
            .then(response => response.json())
            .then(data => {
                ipTableBody.innerHTML = '';
                data.forEach(device => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${device.ip}</td>
                        <td>${device.vlan}</td>
                        <td>${device.client}</td>
                        <td><button class="delete-button" data-id="${device.id}">Supprimer</button></td>
                    `;
                    ipTableBody.appendChild(row);
                });
                attachDeleteHandlers();
            })
            .catch(error => console.error('Erreur lors du chargement des appareils:', error));
    }

    // Fonction pour attacher les gestionnaires d'événements de suppression
    function attachDeleteHandlers() {
        const deleteButtons = document.querySelectorAll('.delete-button');
        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const deviceId = this.getAttribute('data-id');
                deleteDevice(deviceId);
            });
        });
    }

    // Fonction pour supprimer un appareil
    function deleteDevice(id) {
        fetch(`api/delete_device.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadDevices();
            } else {
                alert('Erreur lors de la suppression de l\'appareil.');
            }
        })
        .catch(error => console.error('Erreur lors de la suppression de l\'appareil:', error));
    }

    // Afficher/masquer le formulaire d'ajout
    showAddFormBtn.addEventListener('click', function(e) {
        e.preventDefault();
        addIpForm.style.display = addIpForm.style.display === 'none' ? 'block' : 'none';
    });

    // Gestion du formulaire d'ajout d'IP
    document.getElementById('ipForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const ipAddress = document.getElementById('ipAddress').value;
        const vlan = document.getElementById('vlan').value;

        // Envoyer les données au serveur
        fetch('api/add_ip.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ ip_address: ipAddress, vlan: vlan })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadAvailableIPs();
                addIpForm.style.display = 'none';
                document.getElementById('ipForm').reset();
            }
        });
    });

    // Charger la liste des IPs disponibles
    function loadAvailableIPs() {
        fetch('api/get_available_ips.php')
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('availableIpTableBody');
            tbody.innerHTML = '';
            
            data.forEach(ip => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${ip.ip_address}</td>
                    <td>${ip.vlan}</td>
                    <td>
                        <button class="btn-action" 
                                onclick="assignCustomer('${ip.ip_address}', '${ip.vlan}')">
                            Utiliser
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        });
    }

    // Afficher le formulaire d'attribution client
    window.assignCustomer = function(ip, vlan) {
        document.getElementById('selectedIp').value = ip;
        document.getElementById('selectedVlan').value = vlan;
        assignCustomerForm.style.display = 'block';
        addIpForm.style.display = 'none';
    }

    // Gestion du formulaire d'attribution client
    document.getElementById('customerForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const data = {
            ip_address: document.getElementById('selectedIp').value,
            vlan: document.getElementById('selectedVlan').value,
            customer_name: document.getElementById('customerName').value
        };

        fetch('api/assign_customer.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                assignCustomerForm.style.display = 'none';
                document.getElementById('customerForm').reset();
                loadAvailableIPs();
            }
        });
    });

    // Charger les appareils et les IPs disponibles au démarrage
    loadDevices();
    loadAvailableIPs();
});