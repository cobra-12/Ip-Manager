document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearch');
    const tableBody = document.querySelector('table tbody');
    
    if (!searchInput || !tableBody) return;

    // Fonction pour réinitialiser l'affichage
    function resetSearch() {
        const rows = tableBody.getElementsByTagName('tr');
        for (let row of rows) {
            if (!row.id.includes('no-results-message')) {
                row.style.display = '';
                row.classList.remove('highlight-match');
            }
        }
        updateNoResultsMessage(true);
    }

    // Fonction de recherche intelligente
    function performSearch() {
        const query = searchInput.value.trim().toLowerCase();
        const rows = tableBody.getElementsByTagName('tr');
        let hasMatches = false;

        // Réinitialiser la mise en surbrillance
        resetSearch();

        // Si la recherche est vide, on affiche tout
        if (!query) {
            updateNoResultsMessage(true);
            return;
        }

        // Recherche dans chaque ligne
        for (let row of rows) {
            if (row.id === 'no-results-message') continue;
            
            const cells = row.getElementsByTagName('td');
            let rowText = Array.from(cells).map(cell => cell.textContent.toLowerCase()).join(' ');
            
            if (rowText.includes(query)) {
                row.style.display = '';
                row.classList.add('highlight-match');
                hasMatches = true;
                
                // Mettre en surbrillance les correspondances
                highlightMatches(row, query);
            } else {
                row.style.display = 'none';
            }
        }

        updateNoResultsMessage(hasMatches);
    }

    // Mettre en surbrillance les correspondances
    function highlightMatches(row, query) {
        const cells = row.getElementsByTagName('td');
        const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
        
        for (let cell of cells) {
            const originalContent = cell.textContent;
            const highlighted = originalContent.replace(regex, '<span class="highlight">$1</span>');
            
            if (highlighted !== originalContent) {
                cell.innerHTML = highlighted;
            }
        }
    }

    // Échapper les caractères spéciaux pour les regex
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Mettre à jour le message "Aucun résultat"
    function updateNoResultsMessage(hasMatches) {
        let noResults = document.getElementById('no-results-message');
        
        if (!hasMatches) {
            if (!noResults) {
                noResults = document.createElement('tr');
                noResults.id = 'no-results-message';
                noResults.innerHTML = `
                    <td colspan="7" class="text-center py-4">
                        <div class="no-results-content">
                            <i class="fas fa-search fa-2x mb-2"></i>
                            <p>Aucun résultat trouvé pour "<span class="search-query">${searchInput.value}</span>"</p>
                            <button class="btn-clear-search" id="resetSearchBtn">
                                <i class="fas fa-undo"></i> Réinitialiser la recherche
                            </button>
                        </div>
                    </td>
                `;
                tableBody.appendChild(noResults);
                
                // Gestionnaire pour le bouton de réinitialisation
                document.getElementById('resetSearchBtn').addEventListener('click', function() {
                    searchInput.value = '';
                    resetSearch();
                    searchInput.focus();
                });
            }
        } else if (noResults) {
            noResults.remove();
        }
    }

    // Gestionnaire d'événements avec debounce
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 300);
    });

    // Réinitialiser la recherche
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            resetSearch();
            searchInput.focus();
        });
    }

    // Gestion de la touche Échap
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            resetSearch();
        }
    });

    // Initialisation
    searchInput.focus();
});
