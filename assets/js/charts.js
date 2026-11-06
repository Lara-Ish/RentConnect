/**
 * RentConnect - Business Intelligence Visualizations
 * Chart.js Integration with Claymorphic Aesthetics
 */

'use strict';

const RentConnectCharts = (() => {
  let collectionChartInstance = null;
  let unitCostChartInstance = null;
  let categoryChartInstance = null;

  // Pastel Clay Color Palette for Charts
  const colors = {
    indigo: '#818cf8',
    indigoLight: 'rgba(129, 140, 248, 0.45)',
    mint: '#6ee7b7',
    mintLight: 'rgba(110, 231, 183, 0.5)',
    mintDark: '#10b981',
    rose: '#fda4af',
    roseLight: 'rgba(253, 164, 175, 0.5)',
    amber: '#fde68a',
    amberLight: 'rgba(253, 230, 138, 0.6)',
    sky: '#7dd3fc',
    skyLight: 'rgba(125, 211, 252, 0.5)',
    purple: '#c4b5fd',
    purpleLight: 'rgba(196, 181, 253, 0.5)',
    gridLines: 'rgba(148, 163, 184, 0.15)',
    tooltipBg: '#edf2fb',
    textMain: '#334155'
  };

  /**
   * Universal Claymorphic Tooltip Configuration
   */
  const clayTooltipOptions = {
    backgroundColor: colors.tooltipBg,
    titleColor: '#0f172a',
    bodyColor: '#334155',
    borderColor: 'rgba(255, 255, 255, 0.8)',
    borderWidth: 1,
    padding: 12,
    cornerRadius: 16,
    boxPadding: 6,
    usePointStyle: true,
    shadowOffsetX: 6,
    shadowOffsetY: 6,
    shadowBlur: 14,
    shadowColor: 'rgba(166, 180, 204, 0.4)',
    callbacks: {
      labelTextColor: () => '#334155'
    }
  };

  /**
   * 1. Render Revenue & Collection Analytics Chart
   * Dual-Axis Chart: Billed vs Collected Rent (Bars) + Efficiency Rate % (Line)
   */
  function renderCollectionChart(canvasId, monthlyData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (collectionChartInstance) {
      collectionChartInstance.destroy();
    }

    const labels = monthlyData.map(m => m.month_label || m.month_key);
    const billedRent = monthlyData.map(m => m.billed_rent);
    const collectedRent = monthlyData.map(m => m.collected_rent);
    const efficiencyRate = monthlyData.map(m => m.collection_efficiency_percentage);

    collectionChartInstance = new Chart(ctx, {
      data: {
        labels: labels,
        datasets: [
          {
            type: 'line',
            label: 'Collection Efficiency (%)',
            data: efficiencyRate,
            borderColor: colors.mintDark,
            backgroundColor: colors.mint,
            borderWidth: 3,
            tension: 0.35,
            pointRadius: 6,
            pointHoverRadius: 8,
            pointBackgroundColor: '#ffffff',
            pointBorderColor: colors.mintDark,
            pointBorderWidth: 3,
            yAxisID: 'yEfficiency',
            order: 1
          },
          {
            type: 'bar',
            label: 'Collected Rent (₱)',
            data: collectedRent,
            backgroundColor: colors.indigo,
            borderRadius: 12,
            borderSkipped: false,
            yAxisID: 'yAmount',
            order: 2
          },
          {
            type: 'bar',
            label: 'Billed Rent (₱)',
            data: billedRent,
            backgroundColor: 'rgba(203, 213, 225, 0.7)',
            borderRadius: 12,
            borderSkipped: false,
            yAxisID: 'yAmount',
            order: 3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: {
            position: 'top',
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              color: colors.textMain,
              font: { weight: '600', size: 12 }
            }
          },
          tooltip: {
            ...clayTooltipOptions,
            callbacks: {
              label: function(context) {
                if (context.dataset.yAxisID === 'yEfficiency') {
                  return ` Efficiency: ${context.parsed.y.toFixed(1)}%`;
                }
                return ` ${context.dataset.label}: ₱${context.parsed.y.toLocaleString()}`;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.textMain,
              font: { weight: '600', size: 11 }
            }
          },
          yAmount: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            grid: {
              color: colors.gridLines,
              drawBorder: false
            },
            ticks: {
              color: '#64748b',
              callback: val => `₱${val.toLocaleString()}`
            }
          },
          yEfficiency: {
            type: 'linear',
            position: 'right',
            min: 0,
            max: 100,
            grid: { display: false },
            ticks: {
              color: colors.mintDark,
              callback: val => `${val}%`
            }
          }
        }
      }
    });
  }

  /**
   * 2. Render Maintenance Cost Analysis Per Unit (Bar Chart)
   */
  function renderUnitCostChart(canvasId, unitData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (unitCostChartInstance) {
      unitCostChartInstance.destroy();
    }

    const labels = unitData.map(u => u.unit_number);
    const costs = unitData.map(u => u.total_cost);

    // Color code: highlight outlier units in soft rose clay, normal in pastel sky
    const barColors = unitData.map(u => 
      u.cost_anomaly === 'critical_high_cost' ? colors.rose : colors.sky
    );

    unitCostChartInstance = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Total Maintenance Expense (₱)',
          data: costs,
          backgroundColor: barColors,
          borderRadius: 14,
          borderSkipped: false,
          maxBarThickness: 46
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            ...clayTooltipOptions,
            callbacks: {
              label: (context) => {
                const item = unitData[context.dataIndex];
                return [
                  ` Expense: ₱${item.total_cost.toLocaleString()}`,
                  ` Tickets Logged: ${item.ticket_count}`,
                  ` Avg / Ticket: ₱${item.avg_cost_per_ticket.toFixed(2)}`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.textMain,
              font: { weight: '600' }
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: colors.gridLines,
              drawBorder: false
            },
            ticks: {
              color: '#64748b',
              callback: val => `₱${val.toLocaleString()}`
            }
          }
        }
      }
    });
  }

  /**
   * 3. Render Repair Type Categorization (Doughnut / Clay Ring Chart)
   */
  function renderCategoryChart(canvasId, categoryData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (categoryChartInstance) {
      categoryChartInstance.destroy();
    }

    const labels = categoryData.map(c => c.category);
    const costs = categoryData.map(c => c.total_cost);
    const palette = [colors.indigo, colors.mint, colors.amber, colors.sky, colors.purple, colors.rose];

    categoryChartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: costs,
          backgroundColor: palette.slice(0, labels.length),
          borderWidth: 4,
          borderColor: '#edf2fb',
          hoverOffset: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '66%',
        plugins: {
          legend: {
            position: 'right',
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              color: colors.textMain,
              font: { weight: '600', size: 12 },
              padding: 14
            }
          },
          tooltip: {
            ...clayTooltipOptions,
            callbacks: {
              label: (context) => {
                const cat = categoryData[context.dataIndex];
                return ` ${cat.category}: ₱${cat.total_cost.toLocaleString()} (${cat.cost_share_percentage}%)`;
              }
            }
          }
        }
      }
    });
  }

  return {
    initCharts: (analyticsData) => {
      if (analyticsData.charts) {
        if (analyticsData.charts.monthly_collection_efficiency) {
          renderCollectionChart('chartCollectionEfficiency', analyticsData.charts.monthly_collection_efficiency);
        }
        if (analyticsData.charts.maintenance_by_unit) {
          renderUnitCostChart('chartUnitMaintenance', analyticsData.charts.maintenance_by_unit);
        }
        if (analyticsData.charts.maintenance_by_category) {
          renderCategoryChart('chartCategoryBreakdown', analyticsData.charts.maintenance_by_category);
        }
      }
    }
  };
})();

// TaraRent Charts Global Alias
window.TaraRentCharts = RentConnectCharts;
