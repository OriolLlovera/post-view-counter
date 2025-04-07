document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    const container = document.querySelector('.pps-view-stats-container');
    if (!container) return;
    
    if (typeof jQuery === 'undefined') {
        console.error('jQuery no está cargado');
        return;
    }
    
    jQuery(function($) {
        function filterTable(tableId, searchText) {
            $('#' + tableId + ' tbody tr').each(function() {
                const rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.indexOf(searchText.toLowerCase()) > -1);
            });
        }
  
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
  
        $(container).on('keyup', '.pps-search-input', function() {
            const tableId = $(this).closest('.pps-table-container').find('table').attr('id');
            filterTable(tableId, $(this).val());
        });
  
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
  
        $(container).on('click', '.pps-category-toggle', function() {
            const $toggle = $(this);
            const $content = $toggle.next('.pps-category-content');
            const $icon = $toggle.find('.dashicons');
            
            $toggle.toggleClass('active');
            $content.slideToggle(200);
            $icon.toggleClass('dashicons-arrow-up dashicons-arrow-down');
        });
  
        $('.pps-category-content').hide();
        $('.pps-category-toggle .dashicons').addClass('dashicons-arrow-down');
  
        if (typeof Chart !== 'undefined') {
            if (window.ppsViewStatsData) {
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
            
            if (window.ppsHourlyData) {
                new Chart(
                    document.getElementById('pps-hourly-chart').getContext('2d'),
                    {
                        type: 'line',
                        data: {
                            labels: ppsHourlyData.labels,
                            datasets: [{
                                label: 'Visitas por hora',
                                data: ppsHourlyData.data,
                                backgroundColor: 'rgba(52, 152, 219, 0.2)',
                                borderColor: 'rgba(52, 152, 219, 1)',
                                borderWidth: 2,
                                tension: 0.4,
                                fill: true
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
            
            if (window.ppsDeviceData) {
                new Chart(
                    document.getElementById('pps-device-chart').getContext('2d'),
                    {
                        type: 'pie',
                        data: {
                            labels: ppsDeviceData.labels,
                            datasets: [{
                                data: ppsDeviceData.data,
                                backgroundColor: [
                                    '#3498db', '#2ecc71', '#9b59b6'
                                ]
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
            }
            
            if (window.ppsBrowserData) {
                new Chart(
                    document.getElementById('pps-browser-chart').getContext('2d'),
                    {
                        type: 'doughnut',
                        data: {
                            labels: ppsBrowserData.labels,
                            datasets: [{
                                data: ppsBrowserData.data,
                                backgroundColor: [
                                    '#3498db', '#e74c3c', '#2ecc71', 
                                    '#f1c40f', '#9b59b6', '#1abc9c', 
                                    '#34495e'
                                ]
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
            }
            
            if (window.ppsMonthlyComparisonData) {
                new Chart(
                    document.getElementById('pps-monthly-comparison-chart').getContext('2d'),
                    {
                        type: 'bar',
                        data: {
                            labels: ppsMonthlyComparisonData.labels,
                            datasets: [{
                                label: 'Visitas',
                                data: ppsMonthlyComparisonData.data,
                                backgroundColor: 'rgba(52, 152, 219, 0.7)',
                                borderColor: 'rgba(52, 152, 219, 1)',
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
        }
    });
  });