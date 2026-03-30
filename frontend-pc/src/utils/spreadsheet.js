const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

const loadExcelJs = async () => {
  const module = await import('exceljs')
  return module.default ?? module
}

const loadPapaParse = async () => {
  const module = await import('papaparse')
  return module.default ?? module
}

const triggerDownload = (filename, buffer, mimeType = XLSX_MIME) => {
  const blob = new Blob([buffer], { type: mimeType })
  const url = window.URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  window.URL.revokeObjectURL(url)
}

const normalizeCellValue = (value) => {
  if (value === null || value === undefined) return ''
  if (value instanceof Date) return value.toISOString()
  if (Array.isArray(value)) {
    return value.map((item) => normalizeCellValue(item)).join('')
  }
  if (typeof value === 'object') {
    if (typeof value.text === 'string') return value.text
    if (typeof value.result === 'string' || typeof value.result === 'number') return String(value.result)
    if (Array.isArray(value.richText)) {
      return value.richText.map((item) => item?.text || '').join('')
    }
    if (value.hyperlink && value.text) return String(value.text)
  }
  return String(value)
}

export const exportRowsToXlsx = async ({ filename, sheetName, rows }) => {
  const ExcelJS = await loadExcelJs()
  const workbook = new ExcelJS.Workbook()
  const worksheet = workbook.addWorksheet(sheetName)
  const headers = Object.keys(rows?.[0] || {})

  if (headers.length > 0) {
    worksheet.addRow(headers)
    for (const row of rows) {
      worksheet.addRow(headers.map((header) => row?.[header] ?? ''))
    }
  }

  const buffer = await workbook.xlsx.writeBuffer()
  triggerDownload(filename, buffer)
}

export const exportAoaSheetsToXlsx = async ({ filename, sheets }) => {
  const ExcelJS = await loadExcelJs()
  const workbook = new ExcelJS.Workbook()

  for (const sheetConfig of sheets) {
    const worksheet = workbook.addWorksheet(sheetConfig.name)
    const rows = Array.isArray(sheetConfig.rows) ? sheetConfig.rows : []

    for (const row of rows) {
      worksheet.addRow(Array.isArray(row) ? row : [])
    }

    for (const merge of sheetConfig.merges || []) {
      worksheet.mergeCells(merge.startRow, merge.startCol, merge.endRow, merge.endCol)
    }
  }

  const buffer = await workbook.xlsx.writeBuffer()
  triggerDownload(filename, buffer)
}

export const parseSpreadsheetFile = async (file) => {
  const lowerName = String(file?.name || '').toLowerCase()
  if (lowerName.endsWith('.csv')) {
    const Papa = await loadPapaParse()
    const text = await file.text()
    const result = Papa.parse(text, {
      header: true,
      skipEmptyLines: 'greedy',
    })

    if (Array.isArray(result.errors) && result.errors.length > 0) {
      throw new Error(result.errors[0]?.message || 'CSV 解析失败')
    }

    return Array.isArray(result.data) ? result.data : []
  }

  const ExcelJS = await loadExcelJs()
  const workbook = new ExcelJS.Workbook()
  const buffer = await file.arrayBuffer()
  await workbook.xlsx.load(buffer)

  const worksheet = workbook.worksheets?.[0]
  if (!worksheet) return []

  const headerRow = worksheet.getRow(1)
  const headers = headerRow.values
    .slice(1)
    .map((value) => normalizeCellValue(value).trim())

  const rows = []
  for (let rowNumber = 2; rowNumber <= worksheet.rowCount; rowNumber += 1) {
    const row = worksheet.getRow(rowNumber)
    const normalized = {}

    headers.forEach((header, index) => {
      if (!header) return
      normalized[header] = normalizeCellValue(row.getCell(index + 1).value).trim()
    })

    if (Object.values(normalized).some((value) => value !== '')) {
      rows.push(normalized)
    }
  }

  return rows
}
