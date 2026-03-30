import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import NotificationCenterView from './NotificationCenterView.vue'

const {
  postMock,
  readCurrentUserMock,
  hasPermissionMock,
  messageErrorMock,
  messageSuccessMock,
  messageWarningMock,
} = vi.hoisted(() => ({
  postMock: vi.fn(),
  readCurrentUserMock: vi.fn(),
  hasPermissionMock: vi.fn(),
  messageErrorMock: vi.fn(),
  messageSuccessMock: vi.fn(),
  messageWarningMock: vi.fn(),
}))

vi.mock('../../services/api', () => ({
  default: {
    post: postMock,
  },
}))

vi.mock('../../utils/auth', () => ({
  readCurrentUser: readCurrentUserMock,
  hasPermission: hasPermissionMock,
}))

vi.mock('element-plus', async () => {
  const actual = await vi.importActual('element-plus')
  return {
    ...actual,
    ElMessage: {
      error: messageErrorMock,
      success: messageSuccessMock,
      warning: messageWarningMock,
    },
  }
})

const buildListResponse = (items) => ({
  data: {
    data: items,
  },
})

const mountView = async () => {
  const wrapper = mount(NotificationCenterView, {
    global: {
      plugins: [ElementPlus],
      stubs: {
        PrePlanOrderDetailContent: {
          template: '<div class="detail-stub">detail</div>',
        },
      },
    },
  })

  await flushPromises()

  return wrapper
}

describe('NotificationCenterView', () => {
  beforeEach(() => {
    postMock.mockReset()
    readCurrentUserMock.mockReset()
    hasPermissionMock.mockReset()
    messageErrorMock.mockReset()
    messageSuccessMock.mockReset()
    messageWarningMock.mockReset()

    readCurrentUserMock.mockReturnValue({
      id: 1,
      permissions: ['dispatch', 'notifications'],
    })
    hasPermissionMock.mockImplementation((user, permission) => {
      const permissions = Array.isArray(user?.permissions) ? user.permissions : []
      return permissions.includes(permission)
    })
  })

  it('加载后按置顶、未读、时间倒序展示消息', async () => {
    postMock.mockResolvedValueOnce(buildListResponse([
      {
        id: 1,
        title: '普通未读较早',
        is_pinned: false,
        read_at: null,
        created_at: '2026-03-29 09:00:00',
        meta: {},
      },
      {
        id: 2,
        title: '置顶已读',
        is_pinned: true,
        read_at: '2026-03-29 11:00:00',
        created_at: '2026-03-29 11:00:00',
        meta: {},
      },
      {
        id: 3,
        title: '置顶未读',
        is_pinned: true,
        read_at: null,
        created_at: '2026-03-29 10:00:00',
        meta: {},
      },
      {
        id: 4,
        title: '普通已读最新',
        is_pinned: false,
        read_at: '2026-03-29 12:00:00',
        created_at: '2026-03-29 12:00:00',
        meta: {},
      },
    ]))

    const wrapper = await mountView()

    expect(wrapper.vm.messages.map((item) => item.id)).toEqual([3, 2, 1, 4])
  })

  it('查看驳回订单时会加载详情和版本对比', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 10,
          title: '审核驳回通知',
          is_pinned: false,
          read_at: null,
          created_at: '2026-03-30 09:00:00',
          meta: {
            order_id: 101,
            order_no: 'PO-101',
            audit_status: 'rejected',
          },
        },
      ]))
      .mockResolvedValueOnce({
        data: {
          id: 101,
          audit_status: 'rejected',
          order_no: 'PO-101',
          meta: {},
        },
      })
      .mockResolvedValueOnce({
        data: {
          diffs: [{ field: 'pickup_address', before: '旧地址', after: '新地址' }],
        },
      })

    const wrapper = await mountView()
    await wrapper.vm.openOrderDetail(wrapper.vm.messages[0])
    await flushPromises()

    expect(postMock).toHaveBeenNthCalledWith(2, '/pre-plan-order/detail', { id: 101 })
    expect(postMock).toHaveBeenNthCalledWith(3, '/pre-plan-order/revision-compare', { id: 101 })
    expect(wrapper.vm.detailDialogVisible).toBe(true)
    expect(wrapper.vm.detailOrder?.id).toBe(101)
    expect(wrapper.vm.detailCompareRows).toEqual([
      { field: 'pickup_address', before: '旧地址', after: '新地址' },
    ])
  })

  it('标记已读后会刷新消息列表', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 20,
          title: '未读消息',
          is_pinned: false,
          read_at: null,
          created_at: '2026-03-30 08:00:00',
          meta: {},
        },
      ]))
      .mockResolvedValueOnce({ data: { id: 20 } })
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 20,
          title: '已读消息',
          is_pinned: false,
          read_at: '2026-03-30 08:30:00',
          created_at: '2026-03-30 08:00:00',
          meta: {},
        },
      ]))

    const wrapper = await mountView()
    await wrapper.vm.markRead(20)
    await flushPromises()

    expect(postMock).toHaveBeenNthCalledWith(2, '/message/read', { id: 20 })
    expect(postMock).toHaveBeenNthCalledWith(3, '/message/list', {
      keyword: undefined,
      read_status: 'all',
      message_type: undefined,
      pinned_only: false,
    })
    expect(wrapper.vm.messages[0].read_at).toBe('2026-03-30 08:30:00')
  })

  it('置顶后会刷新列表并按统一规则重排', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 31,
          title: '原未置顶消息',
          is_pinned: false,
          read_at: null,
          created_at: '2026-03-30 08:00:00',
          meta: {},
        },
        {
          id: 32,
          title: '另一条消息',
          is_pinned: false,
          read_at: null,
          created_at: '2026-03-30 09:00:00',
          meta: {},
        },
      ]))
      .mockResolvedValueOnce({ data: { id: 31, is_pinned: true } })
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 31,
          title: '原未置顶消息',
          is_pinned: true,
          read_at: null,
          created_at: '2026-03-30 08:00:00',
          meta: {},
        },
        {
          id: 32,
          title: '另一条消息',
          is_pinned: false,
          read_at: null,
          created_at: '2026-03-30 09:00:00',
          meta: {},
        },
      ]))

    const wrapper = await mountView()
    await wrapper.vm.togglePin(wrapper.vm.messages.find((item) => item.id === 31))
    await flushPromises()

    expect(postMock).toHaveBeenNthCalledWith(2, '/message/pin', {
      id: 31,
      is_pinned: true,
    })
    expect(postMock).toHaveBeenNthCalledWith(3, '/message/list', {
      keyword: undefined,
      read_status: 'all',
      message_type: undefined,
      pinned_only: false,
    })
    expect(wrapper.vm.messages.map((item) => item.id)).toEqual([31, 32])
  })

  it('批量已读在未选中消息时给出提示', async () => {
    postMock.mockResolvedValueOnce(buildListResponse([]))

    const wrapper = await mountView()
    await wrapper.vm.markReadBatch()

    expect(messageWarningMock).toHaveBeenCalledWith('请先勾选消息')
    expect(postMock).toHaveBeenCalledTimes(1)
  })
})
