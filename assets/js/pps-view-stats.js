document.addEventListener('DOMContentLoaded', function() {
  'use strict';
  
  // Contenedor específico del plugin
  const container = document.querySelector('.pps-view-stats-container');
  if (!container) return;
  
  // Verificar si jQuery está disponible
  if (typeof jQuery === 'undefined') {
      console.error('jQuery no está cargado');
      return;
  }
  
  // Usar jQuery con el alias seguro $
  jQuery(function($) {
      // Función para filtrar tablas
      function filterTable(tableId, searchText) {
          $('#' + tableId + ' tbody tr').each(function() {
              const rowText = $(this).text().toLowerCase();
              $(this).toggle(rowText.indexOf(searchText.toLowerCase()) > -1);
          });
      }

      // Función para ordenar tablas
      function sortTable(tableId, columnIndex, sortType, direction) {
          const $table = $('#' + tableId);
          const $rows = $table.find('tbody tr').get();
          const dir = direction === 'asc' ? 1 : -1;
          
          $rows.sort(function(a, b) {
              let aVal = $(a).find('td').eq(columnIndex).text().trim();
              let bVal = $(b).find('td').eq(columnIndex).text().trim();
              
              if (sortType === 'number') {
                  aVal = parseFloat(aVal) || 0;
                  bVal = parseFloat(bVal) || 0;
                  return (aVal - bVal) * dir;
              }
              
              if (sortType === 'date') {
                  aVal = new Date(aVal);
                  bVal = new Date(bVal);
                  return (aVal - bVal) * dir;
              }
              
              return dir * aVal.localeCompare(bVal);
          });
          
          $table.find('tbody').empty().append($rows);
      }

      // Eventos de filtrado
      $(container).on('keyup', '.pps-search-input', function() {
          const tableId = $(this).closest('.pps-table-container').find('table').attr('id');
          filterTable(tableId, $(this).val());
      });

      // Eventos de ordenación
      $(container).on('click', '.pps-stats-table th[data-sort]', function() {
          const $th = $(this);
          const $table = $th.closest('table');
          const tableId = $table.attr('id');
          const columnIndex = $th.index();
          const sortType = $th.data('sort');
          const currentDir = $th.data('sort-dir') || 'none';
          const newDir = currentDir === 'asc' ? 'desc' : 'asc';
          
          $table.find('th').removeClass('sorting-asc sorting-desc')
                .data('sort-dir', 'none');
          
          $th.addClass('sorting-' + newDir)
             .data('sort-dir', newDir);
          
          sortTable(tableId, columnIndex, sortType, newDir);
      });

      // Inicializar gráficos si existen los datos
      if (typeof Chart !== 'undefined' && window.ppsViewStatsData) {
          // Gráfico de tipo de contenido
          new Chart(
              document.getElementById('pps-content-type-chart').getContext('2d'),
              {
                  type: 'doughnut',
                  data: {
                      labels: ['Artículos', 'Páginas'],
                      datasets: [{
                          data: [ppsViewStatsData.postViews, ppsViewStatsData.pageViews],
                          backgroundColor: ['#3498db', '#e74c3c'],
                          borderWidth: 1
                      }]
                  },
                  options: {
                      responsive: true,
                      maintainAspectRatio: false,
                      plugins: {
                          legend: { position: 'bottom' }
                      }
                  }
              }
          );
          
          // Gráfico de periodos de tiempo
          new Chart(
              document.getElementById('pps-time-period-chart').getContext('2d'),
              {
                  type: 'bar',
                  data: {
                      labels: ['Hoy', 'Este Mes', 'Este Año'],
                      datasets: [{
                          label: 'Visitas',
                          data: [
                              ppsViewStatsData.todayViews,
                              ppsViewStatsData.monthViews,
                              ppsViewStatsData.yearViews
                          ],
                          backgroundColor: '#2ecc71',
                          borderWidth: 1
                      }]
                  },
                  options: {
                      responsive: true,
                      maintainAspectRatio: false,
                      scales: {
                          y: { beginAtZero: true }
                      }
                  }
              }
          );
      }
  });
});