/**
 * IPbx Prisma Telecom - Universal Report Hover Summary Tooltip System
 * Exibe um resumo flutuante elegante ao passar o mouse sobre qualquer linha de relatório,
 * SUPRIMINDO inteligentemente o card quando o mouse está sobre o campo de Contato/Origem ou elementos com tooltip próprio.
 */
(function() {
    function initPrismaReportHover() {
        if (typeof $ === 'undefined') return;

        var tooltip = document.getElementById('prisma_report_tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'prisma_report_tooltip';
            document.body.appendChild(tooltip);
        }

        var currentMousePos = null;
        var hoverTimer = null;
        var pendingRow = null;
        var pendingEvent = null;
        var HOVER_DELAY = 1200; // Delay suave

                function isOverSpecificTooltip(e) {
            if (!e || !e.target) return false;
            var el = e.target;
            // Suprime quando sobre contato, ramal, botões, links, checkboxes ou player de áudio
            if (el.closest('[title]') || 
                el.closest('.contact-badge') || 
                el.closest('button') || 
                el.closest('a') || 
                el.closest('input') || 
                el.closest('.sticky-audio-bar') ||
                el.closest('#stickyBottomAudioPlayer')) {
                return true;
            }
            return false;
        }
            return false;
        }

        function hideTooltip() {
            if (hoverTimer) {
                clearTimeout(hoverTimer);
                hoverTimer = null;
            }
            if (tooltip) {
                tooltip.style.opacity = '0';
                tooltip.style.display = 'none';
            }
        }

                function positionTooltip(e) {
            if (!e || !tooltip) return;

            var pageX = e.pageX;
            var pageY = e.pageY;
            var clientY = e.clientY;
            var winHeight = window.innerHeight || $(window).height();

            // Se o cursor estiver próximo ao rodapé (últimos 160px da tela), posiciona o tooltip para cima do cursor
            if (clientY > (winHeight - 180)) {
                var tooltipHeight = tooltip.offsetHeight || 120;
                tooltip.style.top = (pageY - tooltipHeight - 15) + 'px';
            } else {
                tooltip.style.top = (pageY + 15) + 'px';
            }

            var tooltipWidth = tooltip.offsetWidth || 340;
            var winWidth = $(window).width();
            if (pageX + tooltipWidth + 30 > winWidth) {
                tooltip.style.left = (pageX - tooltipWidth - 15) + 'px';
            } else {
                tooltip.style.left = (pageX + 15) + 'px';
            }
        }

        function renderRowTooltip($row, e) {
            if (isOverSpecificTooltip(e)) {
                hideTooltip();
                return;
            }

            var $table = $row.closest('table');
            if ($row.find('th').length > 0 || $row.parent().is('thead')) return;

            var headers = [];
            $table.find('thead th, tr:first th').each(function() {
                var txt = $(this).text().trim();
                headers.push(txt);
            });

            var $cells = $row.children('td');
            if ($cells.length === 0) return;

            var items = [];
            $cells.each(function(idx) {
                var label = headers[idx] || ('Item ' + (idx + 1));
                var val = $(this).text().trim().replace(/\s+/g, ' ');
                
                if (!label || label === '#' || !val || val.length > 120 || label.toLowerCase() === 'opções' || label.toLowerCase() === 'options' || label.toLowerCase() === 'ações') return;
                
                items.push({ label: label, value: val });
            });

            if (items.length === 0) return;

            var itemTitle = items[0] ? items[0].value : 'Detalhes do Relatório';
            var html = '<div style="font-weight: 700; font-size: 13px; color: #c084fc; border-bottom: 1px solid rgba(168,85,247,0.3); padding-bottom: 6px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">' +
                '<span style="background: rgba(168,85,247,0.2); padding: 2px 6px; border-radius: 4px; font-size: 10px; color: #e9d5ff;">RESUMO</span>' +
                '<span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + itemTitle + '</span></div>';
            
            html += '<div style="display: flex; flex-direction: column; gap: 4px;">';
            for (var i = 0; i < items.length; i++) {
                html += '<div style="display: flex; justify-content: space-between; gap: 12px; font-size: 11px; line-height: 1.4;">' +
                    '<span style="color: #a78bfa; font-weight: 600;">' + items[i].label + ':</span>' +
                    '<span style="color: #f3e8ff; text-align: right; font-weight: 400; word-break: break-word;">' + items[i].value + '</span>' +
                    '</div>';
            }
            html += '</div>';

            tooltip.innerHTML = html;
            tooltip.style.opacity = '1';
            currentMousePos = e;
            positionTooltip(e);

            $row.addClass('prisma-report-row-hover');
        }

        $(document).on('mouseenter', 'table tr, .table tr', function(e) {
            var $row = $(this);
            if ($row.find('th').length > 0 || $row.parent().is('thead')) return;

            if (isOverSpecificTooltip(e)) {
                hideTooltip();
                return;
            }

            if (hoverTimer) clearTimeout(hoverTimer);
            pendingRow = $row;
            pendingEvent = e;

            hoverTimer = setTimeout(function() {
                if (pendingRow && pendingEvent) {
                    renderRowTooltip(pendingRow, pendingEvent);
                }
            }, HOVER_DELAY);
        });

        $(document).on('mousemove', 'table tr, .table tr', function(e) {
            if (isOverSpecificTooltip(e)) {
                hideTooltip();
                return;
            }

            pendingEvent = e;
            currentMousePos = e;
            if (tooltip && tooltip.style.display !== 'none') {
                positionTooltip(e);
            }
        });

        $(document).on('mouseleave', 'table tr, .table tr', function() {
            hideTooltip();
            pendingRow = null;
            pendingEvent = null;
            $(this).removeClass('prisma-report-row-hover');
        });

        $(window).on('scroll resize', function() {
            if (currentMousePos && tooltip && tooltip.style.display !== 'none') {
                positionTooltip(currentMousePos);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrismaReportHover);
    } else {
        initPrismaReportHover();
    }
})();
