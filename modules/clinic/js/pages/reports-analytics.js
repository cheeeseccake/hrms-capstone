function initClinicReports() {
    const root = document.querySelector('[data-clinic-reports]');
    if (!root || root.dataset.reportsInitialized === 'true') return;
    root.dataset.reportsInitialized = 'true';
    const chartData = JSON.parse(root.dataset.chartData || '{}');
    const blues = ['#174B91', '#2F68AE', '#4C82C2', '#6B9AD0', '#8BB3DE', '#ADCBE8', '#376AA5', '#5885B8'];
    if (typeof window.Chart === 'function') {
        root.querySelectorAll('[data-report-chart]').forEach((canvas) => {
            const definition = chartData[canvas.dataset.reportChart];
            if (!definition) return;
            const isLine = definition.type === 'line';
            new window.Chart(canvas.getContext('2d'), {
                type: definition.type,
                data: {
                    labels: definition.labels,
                    datasets: [{
                        label: definition.label,
                        data: definition.values,
                        borderColor: blues[0],
                        backgroundColor: isLine ? 'rgba(23, 75, 145, 0.14)' : blues,
                        borderWidth: 2,
                        tension: 0.28,
                        fill: isLine,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 350 },
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#344d6d', boxWidth: 10, font: { size: 10 } } },
                    },
                    scales: ['bar', 'line'].includes(definition.type) ? {
                        x: { ticks: { color: '#5f7087', font: { size: 10 } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0, color: '#5f7087', font: { size: 10 } }, grid: { color: '#e8eef5' } },
                    } : {},
                },
            });
        });
    }

    const search = root.querySelector('[data-report-history-search]');
    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase();
        root.querySelectorAll('[data-report-history-row]').forEach((row) => {
            row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
        });
    });

    root.querySelector('[data-report-print]')?.addEventListener('click', () => window.print());
}

function scheduleClinicReportsInit() {
    window.requestAnimationFrame(initClinicReports);
}

window.addEventListener('page:loaded', scheduleClinicReportsInit);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleClinicReportsInit, { once: true });
} else {
    scheduleClinicReportsInit();
}
