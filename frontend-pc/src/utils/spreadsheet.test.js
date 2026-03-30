import { describe, expect, it } from 'vitest'
import ExcelJS from 'exceljs'
import {
  normalizeCellValue,
  normalizeWorksheetRows,
  parseSpreadsheetFile,
} from './spreadsheet'

describe('spreadsheet utils', () => {
  it('normalizeCellValue supports common exceljs value shapes', () => {
    expect(normalizeCellValue(null)).toBe('')
    expect(normalizeCellValue(new Date('2026-03-30T08:00:00.000Z'))).toBe('2026-03-30T08:00:00.000Z')
    expect(normalizeCellValue({ text: '链接文本' })).toBe('链接文本')
    expect(normalizeCellValue({ result: 12.5 })).toBe('12.5')
    expect(normalizeCellValue({ richText: [{ text: '调度' }, { text: '任务' }] })).toBe('调度任务')
    expect(normalizeCellValue(['A', { text: 'B' }, 3])).toBe('AB3')
  })

  it('normalizeWorksheetRows drops empty rows and trims headers', () => {
    const workbook = new ExcelJS.Workbook()
    const sheet = workbook.addWorksheet('测试')
    sheet.addRow([' 订单号 ', '客户'])
    sheet.addRow(['PO-001', '客户A'])
    sheet.addRow(['', ''])
    sheet.addRow(['PO-002', '客户B'])

    expect(normalizeWorksheetRows(sheet)).toEqual([
      { 订单号: 'PO-001', 客户: '客户A' },
      { 订单号: 'PO-002', 客户: '客户B' },
    ])
  })

  it('parseSpreadsheetFile can parse csv files', async () => {
    const file = {
      name: 'orders.csv',
      text: async () => '订单号,客户\nPO-001,客户A\nPO-002,客户B\n',
    }

    await expect(parseSpreadsheetFile(file)).resolves.toEqual([
      { 订单号: 'PO-001', 客户: '客户A' },
      { 订单号: 'PO-002', 客户: '客户B' },
    ])
  })

  it('parseSpreadsheetFile can parse xlsx files', async () => {
    const workbook = new ExcelJS.Workbook()
    const sheet = workbook.addWorksheet('计划单')
    sheet.addRow(['订单号', '客户'])
    sheet.addRow(['PO-100', '客户甲'])
    sheet.addRow(['PO-200', '客户乙'])
    const buffer = await workbook.xlsx.writeBuffer()

    const file = {
      name: 'orders.xlsx',
      arrayBuffer: async () => buffer,
    }

    await expect(parseSpreadsheetFile(file)).resolves.toEqual([
      { 订单号: 'PO-100', 客户: '客户甲' },
      { 订单号: 'PO-200', 客户: '客户乙' },
    ])
  })
})
